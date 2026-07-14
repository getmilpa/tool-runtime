<?php

/**
 * This file is part of Milpa ToolRuntime — the AI tool-execution runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Contracts\PolicyRuleInterface;
use Milpa\ToolRuntime\Contracts\PolicyRuleProviderInterface;

/**
 * Minimal in-memory {@see PolicyRuleInterface} test double — returns whatever the test wired
 * up, no persistence.
 */
final class FakePolicyRule implements PolicyRuleInterface
{
    /**
     * @param list<string>|null $requiresScopes
     */
    public function __construct(
        private readonly ?int $id,
        private readonly string $effect,
        private readonly ?array $requiresScopes = null,
        private readonly ?string $description = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEffect(): string
    {
        return $this->effect;
    }

    public function getRequiresScopes(): ?array
    {
        return $this->requiresScopes;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}

/**
 * {@see PolicyRuleProviderInterface} test double that always returns the single rule it was
 * constructed with, regardless of channel/principal/tool — enough to exercise
 * {@see PolicyGate}'s DB-rule branch without a real (Doctrine-backed) provider.
 */
final class FakePolicyRuleProvider implements PolicyRuleProviderInterface
{
    public function __construct(private readonly PolicyRuleInterface $rule)
    {
    }

    public function findMatchingRule(string $channel, ?string $principal, string $toolName, bool $mutating): ?PolicyRuleInterface
    {
        return $this->rule;
    }
}

class PolicyGateTest extends TestCase
{
    private PolicyGate $policyGate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyGate = new PolicyGate();
    }

    private function createTool(
        string $name = 'test_tool',
        array $scopes = [],
        bool $mutating = false,
        bool $requiresConfirmation = false
    ): ToolDefinition {
        return new ToolDefinition(
            name: $name,
            description: 'Test tool',
            inputSchema: [],
            callback: fn () => null,
            scopes: $scopes,
            mutating: $mutating,
            requiresConfirmation: $requiresConfirmation
        );
    }

    public function testAuthorizeCliChannelAllowsAll(): void
    {
        $ctx = ToolContext::cli();
        $tool = $this->createTool(scopes: ['admin:write']);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertTrue($result->allowed);
    }

    public function testAuthorizeMcpChannelRequiresAuth(): void
    {
        // MCP channel requires authentication - without principal should fail
        $ctx = ToolContext::mcp('req-123');
        $tool = $this->createTool(name: 'admin_action', scopes: ['admin:write']);

        $result = $this->policyGate->authorize($ctx, $tool);

        // MCP requires auth, and mcp() without principal defaults to 'mcp'
        // but require_auth checks if principal is empty, and 'mcp' is not empty
        // So it should pass auth check, but fail scope check (no scopes passed) — the message
        // must name the tool AND list what the context actually has, not just say "missing".
        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('Missing required scope', $result->reason);
        $this->assertStringContainsString('admin_action', $result->reason);
        $this->assertStringContainsString('admin:write', $result->reason);
    }

    /**
     * Item 3 (0.3 design): a channel with `require_auth: true` denied a truly unauthenticated
     * caller (empty `principal`) with the mute "Authentication required for channel: mcp" —
     * costing the first `mcp` transport consumer a debugging session (see the DX friction log
     * referenced in the 0.3 spec) because nothing in the message named the failing CHECK
     * (`require_auth`) or said what was missing. The message must now say both.
     */
    public function testAuthorizeChannelRequiringAuthWithNoPrincipalStatesWhichCheckFailed(): void
    {
        $ctx = new ToolContext(principal: null, channel: 'mcp');
        $tool = $this->createTool();

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString("channel 'mcp'", $result->reason);
        $this->assertStringContainsString('require_auth', $result->reason);
        $this->assertStringContainsString('none provided', $result->reason);
    }

    public function testAuthorizeMcpChannelWithAuthAndScopes(): void
    {
        // MCP with proper auth and matching scopes should pass
        $ctx = ToolContext::mcp('req-123', 'user:123', ['admin:write']);
        $tool = $this->createTool(scopes: ['admin:write']);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertTrue($result->allowed);
    }

    public function testAuthorizeTelegramWithMatchingScope(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: ['notes:read', 'notes:write']
        );
        $tool = $this->createTool(scopes: ['notes:read']);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertTrue($result->allowed);
    }

    public function testAuthorizeTelegramWithMissingScope(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: ['notes:read']
        );
        $tool = $this->createTool(name: 'admin_action', scopes: ['admin:write']);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertFalse($result->allowed);
        // States which tool, what it needs, AND what the context actually carries — not just
        // "missing required scope" with no way to tell which of the two sides is at fault.
        $this->assertStringContainsString('Missing required scope', $result->reason);
        $this->assertStringContainsString('admin_action', $result->reason);
        $this->assertStringContainsString('admin:write', $result->reason);
        $this->assertStringContainsString('notes:read', $result->reason);
    }

    public function testAuthorizeWithWildcardScope(): void
    {
        $ctx = new ToolContext(
            principal: 'admin',
            channel: 'web',
            scopes: ['*']  // Wildcard = full access
        );
        $tool = $this->createTool(scopes: ['admin:super:secret']);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertTrue($result->allowed);
    }

    public function testAuthorizeToolWithNoScopes(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: []  // No scopes
        );
        $tool = $this->createTool(scopes: []);  // Tool doesn't require scopes

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertTrue($result->allowed);
    }

    public function testAuthorizeWithAnyMatchingScope(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'web',
            scopes: ['users:read']  // Has one of required scopes
        );
        $tool = $this->createTool(scopes: ['users:read', 'admin:read']);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertTrue($result->allowed);
    }

    public function testRequiresConfirmationWhenToolExplicitlyRequires(): void
    {
        $ctx = ToolContext::cli();
        $tool = $this->createTool(requiresConfirmation: true);

        $result = $this->policyGate->requiresConfirmation($ctx, $tool);

        $this->assertTrue($result);
    }

    public function testRequiresConfirmationBasedOnChannelPolicy(): void
    {
        // Set a custom policy with require_confirmation_for_mutating
        $this->policyGate->setChannelPolicy('custom_channel', [
            'allow_all' => false,
            'require_confirmation_for_mutating' => true,
        ]);

        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'custom_channel',
            scopes: ['*']
        );
        $tool = $this->createTool(mutating: true);

        $result = $this->policyGate->requiresConfirmation($ctx, $tool);

        $this->assertTrue($result);
    }

    public function testTelegramRequiresConfirmationForMutating(): void
    {
        // Telegram channel has require_confirmation_for_mutating: true by default
        $ctx = ToolContext::telegram('chat123', 'user456');
        $tool = $this->createTool(mutating: true);

        $result = $this->policyGate->requiresConfirmation($ctx, $tool);

        $this->assertTrue($result);
    }

    public function testDoesNotRequireConfirmationForReadOnlyTool(): void
    {
        $ctx = ToolContext::telegram('chat123', 'user456');
        $tool = $this->createTool(mutating: false, requiresConfirmation: false);

        $result = $this->policyGate->requiresConfirmation($ctx, $tool);

        $this->assertFalse($result);
    }

    public function testSetCustomChannelPolicy(): void
    {
        // Use block_mutating to block mutating operations
        $this->policyGate->setChannelPolicy('api', [
            'allow_all' => false,
            'block_mutating' => true,
        ]);

        $ctx = new ToolContext(
            principal: 'api_client',
            channel: 'api',
            scopes: ['*']
        );
        $tool = $this->createTool(name: 'delete_everything', mutating: true);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertFalse($result->allowed);
        // Names the tool AND the failing policy flag (block_mutating), not just "blocked".
        $this->assertStringContainsString('blocked', $result->reason);
        $this->assertStringContainsString('delete_everything', $result->reason);
        $this->assertStringContainsString("channel 'api'", $result->reason);
        $this->assertStringContainsString('block_mutating', $result->reason);
    }

    public function testAuthorizeUnknownChannelAllowsByDefault(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'unknown_channel',
            scopes: ['*']
        );
        $tool = $this->createTool();

        $result = $this->policyGate->authorize($ctx, $tool);

        // Unknown channel has no policy, so allow_all is false but no blocked ops
        $this->assertTrue($result->allowed);
    }

    public function testDbRuleScopeRequirementDenialStatesRuleToolAndScopes(): void
    {
        $this->policyGate->setRuleProvider(new FakePolicyRuleProvider(
            new FakePolicyRule(id: 7, effect: 'allow', requiresScopes: ['admin:write']),
        ));

        $ctx = new ToolContext(principal: 'user:123', channel: 'api', scopes: ['user:read']);
        $tool = $this->createTool(name: 'admin_action');

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('#7', $result->reason);
        $this->assertStringContainsString('admin_action', $result->reason);
        $this->assertStringContainsString('admin:write', $result->reason);
        $this->assertStringContainsString('user:read', $result->reason);
    }

    public function testDbRuleDenyEffectWithoutDescriptionStatesRuleToolChannelAndPrincipal(): void
    {
        $this->policyGate->setRuleProvider(new FakePolicyRuleProvider(
            new FakePolicyRule(id: 9, effect: 'deny', description: null),
        ));

        $ctx = new ToolContext(principal: 'user:123', channel: 'api', scopes: ['*']);
        $tool = $this->createTool(name: 'admin_action');

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('#9', $result->reason);
        $this->assertStringContainsString('admin_action', $result->reason);
        $this->assertStringContainsString("channel 'api'", $result->reason);
        $this->assertStringContainsString('user:123', $result->reason);
    }

    public function testDbRuleDenyEffectWithDescriptionUsesTheHostSuppliedDescriptionVerbatim(): void
    {
        $this->policyGate->setRuleProvider(new FakePolicyRuleProvider(
            new FakePolicyRule(id: 3, effect: 'deny', description: 'blocked by host compliance rule'),
        ));

        $ctx = new ToolContext(principal: 'user:123', channel: 'api', scopes: ['*']);
        $tool = $this->createTool(name: 'admin_action');

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertFalse($result->allowed);
        $this->assertEquals('blocked by host compliance rule', $result->reason);
    }
}
