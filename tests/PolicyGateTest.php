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
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use Milpa\ToolRuntime\Contracts\ToolContext;

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
        $tool = $this->createTool(scopes: ['admin:write']);

        $result = $this->policyGate->authorize($ctx, $tool);

        // MCP requires auth, and mcp() without principal defaults to 'mcp'
        // but require_auth checks if principal is empty, and 'mcp' is not empty
        // So it should pass auth check, but fail scope check (no scopes passed)
        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('Missing required scope', $result->reason);
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
        $tool = $this->createTool(scopes: ['admin:write']);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('Missing required scope', $result->reason);
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
        $tool = $this->createTool(mutating: true);

        $result = $this->policyGate->authorize($ctx, $tool);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('blocked', $result->reason);
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
}
