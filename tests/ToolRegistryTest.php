<?php

/**
 * This file is part of Milpa ToolRuntime — the AI tool-execution runtime of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\Exceptions\ToolAlreadyRegisteredException;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;
use Psr\Log\LoggerInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;

class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registry = new ToolRegistry($this->logger);
    }

    public function testRegisterTool(): void
    {
        $this->registry->register(
            'test_tool',
            'A test tool',
            ['type' => 'object', 'properties' => []],
            fn ($args) => 'result'
        );

        $this->assertTrue($this->registry->has('test_tool'));
    }

    public function testRegisterDuplicateToolThrowsException(): void
    {
        $this->registry->register(
            'my_tool',
            'First registration',
            [],
            fn ($args) => 'first'
        );

        $this->expectException(ToolAlreadyRegisteredException::class);
        $this->expectExceptionMessage('Tool already registered: my_tool');

        $this->registry->register(
            'my_tool',
            'Second registration',
            [],
            fn ($args) => 'second'
        );
    }

    public function testHasReturnsFalseForUnregisteredTool(): void
    {
        $this->assertFalse($this->registry->has('nonexistent_tool'));
    }

    public function testGetToolsReturnsAllRegisteredTools(): void
    {
        $this->registry->register('tool1', 'Tool 1', [], fn () => null);
        $this->registry->register('tool2', 'Tool 2', [], fn () => null);
        $this->registry->register('tool3', 'Tool 3', [], fn () => null);

        $tools = $this->registry->getTools();

        $this->assertCount(3, $tools);
        $names = array_column($tools, 'name');
        $this->assertContains('tool1', $names);
        $this->assertContains('tool2', $names);
        $this->assertContains('tool3', $names);
    }

    public function testGetToolsReturnsCorrectStructure(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
        ];

        $this->registry->register('my_tool', 'My description', $schema, fn () => null);

        $tools = $this->registry->getTools();

        $this->assertEquals('my_tool', $tools[0]['name']);
        $this->assertEquals('My description', $tools[0]['description']);
        $this->assertEquals($schema, $tools[0]['inputSchema']);
    }

    public function testCallNonexistentToolReturnsError(): void
    {
        $result = $this->registry->call('nonexistent', []);

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::TOOL_NOT_FOUND, $result->meta['code']);
        $this->assertStringContainsString('nonexistent', $result->error);
    }

    public function testCallExecutesTool(): void
    {
        $this->registry->register(
            'add',
            'Add two numbers',
            [
                'type' => 'object',
                'properties' => [
                    'a' => ['type' => 'integer'],
                    'b' => ['type' => 'integer'],
                ],
            ],
            fn ($args) => $args['a'] + $args['b']
        );

        $result = $this->registry->call('add', ['a' => 5, 'b' => 3]);

        $this->assertTrue($result->success);
        $this->assertEquals(8, $result->data);
    }

    public function testCallWithValidationError(): void
    {
        $this->registry->register(
            'greet',
            'Greet user',
            [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
            ],
            fn ($args) => "Hello, {$args['name']}!"
        );

        $result = $this->registry->call('greet', []); // Missing required 'name'

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::VALIDATION_ERROR, $result->meta['code']);
        $this->assertStringContainsString('name', $result->error);
    }

    public function testCallWithAuthorizationError(): void
    {
        $this->registry->register(
            'admin_action',
            'Admin only action',
            [],
            fn ($args) => 'admin result',
            ToolOptions::fromArray(['scopes' => ['admin:write']])
        );

        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: ['user:read']  // Missing admin:write scope
        );

        $result = $this->registry->call('admin_action', [], $ctx);

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::FORBIDDEN, $result->meta['code']);
    }

    public function testCallAppliesClamps(): void
    {
        $receivedArgs = null;
        $this->registry->register(
            'paginated_list',
            'List with pagination',
            [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer'],
                ],
            ],
            function ($args) use (&$receivedArgs) {
                $receivedArgs = $args;
                return 'result';
            },
            ToolOptions::fromArray(['clamps' => ['page' => ['min' => 1, 'max' => 100]]])
        );

        $this->registry->call('paginated_list', ['page' => 500]);

        $this->assertEquals(100, $receivedArgs['page']);
    }

    public function testCallWithToolReturningToolResult(): void
    {
        $this->registry->register(
            'custom_result',
            'Returns custom ToolResult',
            [],
            function ($args) {
                return ToolResult::success(['custom' => 'data']);
            }
        );

        $result = $this->registry->call('custom_result', []);

        $this->assertTrue($result->success);
        $this->assertEquals(['custom' => 'data'], $result->data);
    }

    public function testCallHandlesException(): void
    {
        $this->registry->register(
            'throwing_tool',
            'This tool throws',
            [],
            fn ($args) => throw new \RuntimeException('Something went wrong')
        );

        $result = $this->registry->call('throwing_tool', []);

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::INTERNAL_ERROR, $result->meta['code']);
        $this->assertStringContainsString('Something went wrong', $result->error);
    }

    public function testCallInjectsContext(): void
    {
        $receivedCtx = null;
        $this->registry->register(
            'context_aware',
            'Uses context',
            [],
            function ($args) use (&$receivedCtx) {
                $receivedCtx = $args['_ctx'] ?? null;
                return 'done';
            }
        );

        $ctx = new ToolContext(
            principal: 'user:456',
            channel: 'web',
            scopes: ['read']
        );

        $this->registry->call('context_aware', [], $ctx);

        $this->assertInstanceOf(ToolContext::class, $receivedCtx);
        $this->assertEquals('user:456', $receivedCtx->principal);
        $this->assertEquals('web', $receivedCtx->channel);
    }

    public function testCallWithDefaultCliContext(): void
    {
        $receivedCtx = null;
        $this->registry->register(
            'check_context',
            'Check default context',
            [],
            function ($args) use (&$receivedCtx) {
                $receivedCtx = $args['_ctx'] ?? null;
                return 'done';
            }
        );

        $this->registry->call('check_context', []); // No context provided

        $this->assertInstanceOf(ToolContext::class, $receivedCtx);
        $this->assertEquals('cli', $receivedCtx->channel);
    }

    public function testCallRequiresConfirmation(): void
    {
        $this->registry->register(
            'delete_all',
            'Delete all records',
            [],
            fn ($args) => 'deleted',
            ToolOptions::fromArray(['requiresConfirmation' => true])
        );

        // Create a context that doesn't have CLI's allow_all policy
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'custom',
            scopes: ['*']
        );

        // Set a custom policy for this channel that requires confirmation
        $this->registry->getPolicyGate()->setChannelPolicy('custom', [
            'allow_all' => true,
        ]);

        $result = $this->registry->call('delete_all', [], $ctx);

        // Tool has requiresConfirmation=true, so it should require confirmation
        $this->assertTrue($result->requiresConfirmation());
        $this->assertNotNull($result->getConfirmToken());
    }

    public function testCallWithConfirmToken(): void
    {
        $executed = false;
        $this->registry->register(
            'delete_item',
            'Delete an item',
            [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
            function ($args) use (&$executed) {
                $executed = true;
                return "Deleted item {$args['id']}";
            },
            ToolOptions::fromArray(['requiresConfirmation' => true])
        );

        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'custom',
            scopes: ['*']
        );

        $this->registry->getPolicyGate()->setChannelPolicy('custom', [
            'allow_all' => true,
        ]);

        // First call - should return confirmation request
        $result1 = $this->registry->call('delete_item', ['id' => 42], $ctx);
        $this->assertTrue($result1->requiresConfirmation());
        $confirmToken = $result1->getConfirmToken();

        // Second call with token - should execute
        $result2 = $this->registry->call('delete_item', ['id' => 42, 'confirm_token' => $confirmToken], $ctx);
        $this->assertTrue($result2->success);
        $this->assertTrue($executed);
        $this->assertEquals('Deleted item 42', $result2->data);
    }

    public function testCallWithInvalidConfirmToken(): void
    {
        $this->registry->register(
            'delete_item',
            'Delete an item',
            [],
            fn ($args) => 'deleted',
            ToolOptions::fromArray(['requiresConfirmation' => true])
        );

        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'custom',
            scopes: ['*']
        );

        $this->registry->getPolicyGate()->setChannelPolicy('custom', [
            'allow_all' => true,
        ]);

        $result = $this->registry->call('delete_item', ['confirm_token' => 'invalid_token'], $ctx);

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::VALIDATION_ERROR, $result->meta['code']);
        $this->assertStringContainsString('Invalid or expired', $result->error);
    }

    public function testGetDefinition(): void
    {
        $this->registry->register(
            'my_tool',
            'My description',
            ['type' => 'object'],
            fn () => null,
            ToolOptions::fromArray(['scopes' => ['read'], 'mutating' => true])
        );

        $definition = $this->registry->getDefinition('my_tool');

        $this->assertNotNull($definition);
        $this->assertEquals('my_tool', $definition->name);
        $this->assertEquals('My description', $definition->description);
        $this->assertEquals(['read'], $definition->scopes);
        $this->assertTrue($definition->mutating);
    }

    public function testGetDefinitionReturnsNullForUnknown(): void
    {
        $definition = $this->registry->getDefinition('unknown');

        $this->assertNull($definition);
    }

    public function testMetaContainsCorrectInfo(): void
    {
        $this->registry->register(
            'timed_tool',
            'Tool with timing',
            [],
            function ($args) {
                usleep(10000); // 10ms
                return 'done';
            }
        );

        $ctx = new ToolContext(
            principal: 'user:789',
            channel: 'telegram',
            scopes: ['*']
        );

        $result = $this->registry->call('timed_tool', [], $ctx);

        $this->assertEquals('timed_tool', $result->meta['tool']);
        $this->assertEquals('telegram', $result->meta['channel']);
        $this->assertEquals('user:789', $result->meta['principal']);
        $this->assertGreaterThanOrEqual(10, $result->meta['took_ms']);
        $this->assertNotEmpty($result->meta['request_id']);
    }

    public function testGetPolicyGate(): void
    {
        $policyGate = $this->registry->getPolicyGate();

        $this->assertNotNull($policyGate);
    }

    public function testGetConfirmationStore(): void
    {
        $store = $this->registry->getConfirmationStore();

        $this->assertNotNull($store);
    }

    // =========================================================================
    // PLAN MODE TESTS
    // =========================================================================

    public function testCallInPlanModeReturnsPreviewWithoutExecuting(): void
    {
        $executed = false;
        $this->registry->register(
            'plan_test',
            'Test tool for plan mode',
            [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'integer'],
                ],
            ],
            function ($args) use (&$executed) {
                $executed = true;
                return 'executed';
            },
            ToolOptions::fromArray(['mutating' => true, 'scopes' => ['write']])
        );

        $ctx = ToolContext::cli()->asPlan();

        $result = $this->registry->call('plan_test', ['value' => 42], $ctx);

        $this->assertTrue($result->success);
        $this->assertFalse($executed, 'Tool callback should NOT be executed in plan mode');
        $this->assertArrayHasKey('plan', $result->data);
        $this->assertEquals('plan_test', $result->data['plan']['tool']);
        $this->assertTrue($result->data['plan']['mutating']);
        $this->assertEquals(['write'], $result->data['plan']['scopes_required']);
        $this->assertEquals(['value' => 42], $result->data['plan']['args']);
    }

    public function testCallInPlanModeStillValidates(): void
    {
        $this->registry->register(
            'validated_tool',
            'Tool with validation',
            [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
            ],
            fn ($args) => 'result'
        );

        $ctx = ToolContext::cli()->asPlan();

        $result = $this->registry->call('validated_tool', [], $ctx); // Missing required 'name'

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::VALIDATION_ERROR, $result->meta['code']);
    }

    public function testCallInPlanModeStillAuthorizes(): void
    {
        $this->registry->register(
            'admin_tool',
            'Admin only tool',
            [],
            fn ($args) => 'result',
            ToolOptions::fromArray(['scopes' => ['admin:write']])
        );

        $ctx = (new ToolContext(
            principal: 'user:123',
            channel: 'web',
            scopes: ['user:read']  // Missing admin:write
        ))->asPlan();

        $result = $this->registry->call('admin_tool', [], $ctx);

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::FORBIDDEN, $result->meta['code']);
    }

    public function testPlanModeReportsRequiresConfirmation(): void
    {
        $this->registry->register(
            'destructive_tool',
            'Destructive tool',
            [],
            fn ($args) => 'destroyed',
            ToolOptions::fromArray(['requiresConfirmation' => true])
        );

        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'custom',
            scopes: ['*'],
            mode: 'plan'
        );

        $this->registry->getPolicyGate()->setChannelPolicy('custom', [
            'allow_all' => true,
        ]);

        $result = $this->registry->call('destructive_tool', [], $ctx);

        $this->assertTrue($result->success);
        $this->assertArrayHasKey('plan', $result->data);
        $this->assertTrue($result->data['plan']['requires_confirmation']);
    }

    // =========================================================================
    // RATE LIMITING TESTS
    // =========================================================================

    public function testCallWithRateLimiter(): void
    {
        $rateLimiter = new \Milpa\ToolRuntime\RateLimiting\InMemoryRateLimiter();
        $this->registry->setRateLimiter($rateLimiter);

        $this->registry->register(
            'rate_limited_tool',
            'A tool with rate limiting',
            [],
            fn ($args) => 'success'
        );

        // First call should succeed
        $result = $this->registry->call('rate_limited_tool', []);
        $this->assertTrue($result->success);
    }

    public function testRateLimitExceeded(): void
    {
        $rateLimiter = new \Milpa\ToolRuntime\RateLimiting\InMemoryRateLimiter();
        $this->registry->setRateLimiter($rateLimiter);

        $this->registry->register(
            'expensive_tool',
            'A mutating tool (costs 5 tokens)',
            [],
            fn ($args) => 'success',
            ToolOptions::fromArray(['mutating' => true])
        );

        $ctx = ToolContext::cli();

        // Call enough times to exceed rate limit (100 tokens, 5 per call = 20 calls max)
        for ($i = 0; $i < 20; $i++) {
            $result = $this->registry->call('expensive_tool', [], $ctx);
            $this->assertTrue($result->success, "Call $i should succeed");
        }

        // 21st call should be rate limited
        $result = $this->registry->call('expensive_tool', [], $ctx);
        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::RATE_LIMITED, $result->meta['code']);
    }

    public function testRateLimitCostDiffersByMutating(): void
    {
        $rateLimiter = new \Milpa\ToolRuntime\RateLimiting\InMemoryRateLimiter();
        $this->registry->setRateLimiter($rateLimiter);

        $this->registry->register(
            'read_tool',
            'Read only tool (costs 1 token)',
            [],
            fn ($args) => 'read',
            ToolOptions::fromArray(['mutating' => false])
        );

        $ctx = ToolContext::cli();

        // 100 read calls should all succeed (1 token each = 100 tokens)
        for ($i = 0; $i < 100; $i++) {
            $result = $this->registry->call('read_tool', [], $ctx);
            $this->assertTrue($result->success, "Read call $i should succeed");
        }

        // 101st call should be rate limited
        $result = $this->registry->call('read_tool', [], $ctx);
        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::RATE_LIMITED, $result->meta['code']);
    }

    public function testGetAndSetRateLimiter(): void
    {
        $this->assertNull($this->registry->getRateLimiter());

        $rateLimiter = new \Milpa\ToolRuntime\RateLimiting\InMemoryRateLimiter();
        $this->registry->setRateLimiter($rateLimiter);

        $this->assertSame($rateLimiter, $this->registry->getRateLimiter());
    }

    // =========================================================================
    // CONTEXT MODE TESTS
    // =========================================================================

    public function testToolContextModes(): void
    {
        $ctx = ToolContext::cli();
        $this->assertEquals('execute', $ctx->mode);
        $this->assertTrue($ctx->isExecuteMode());
        $this->assertFalse($ctx->isPlanMode());

        $planCtx = $ctx->asPlan();
        $this->assertEquals('plan', $planCtx->mode);
        $this->assertFalse($planCtx->isExecuteMode());
        $this->assertTrue($planCtx->isPlanMode());
    }

    public function testMcpContextWithExplicitScopes(): void
    {
        $ctx = ToolContext::mcp('req-123', 'user:456', ['tools:read', 'tools:write']);

        $this->assertEquals('mcp', $ctx->channel);
        $this->assertEquals('user:456', $ctx->principal);
        $this->assertEquals(['tools:read', 'tools:write'], $ctx->scopes);
        $this->assertTrue($ctx->hasAnyScope(['tools:read']));
        $this->assertFalse($ctx->hasAnyScope(['admin:*']));
    }
}
