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

namespace Milpa\ToolRuntime\Tests\Verification;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\VerificationTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the "policy dividend" the tool-runtime 0.3 design doc calls out: because
 * `request_verification` and `resolve_verification` are separate tools (not one tool with a
 * conditional field), a host can open `request_*` to any principal that can reach the
 * registry while restricting `resolve_*` to specific principals — using nothing more exotic
 * than {@see \Milpa\ValueObjects\Tooling\ToolOptions::$scopes}, the same mechanism every
 * other scoped tool in this package uses (see the README's Verification section for the
 * narrative version of this exact scenario).
 */
final class VerificationToolPolicyDividendTest extends TestCase
{
    private function registryWithScopedResolve(): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        (new VerificationTool(new HumanVerifier(), resolveScopes: ['verification:resolve']))->register($registry);

        return $registry;
    }

    public function testRequestVerificationIsOpenToAnyPrincipalRegardlessOfScopes(): void
    {
        $registry = $this->registryWithScopedResolve();
        $worker = new ToolContext(principal: 'agent:worker', channel: 'mcp', scopes: ['tasks:write']);

        $result = $registry->call(VerificationTool::REQUEST_NAME, [
            'subject' => 'gate:report.publish',
        ], $worker);

        $this->assertTrue($result->success);
    }

    public function testResolveVerificationDeniesAPrincipalWithoutTheResolveScope(): void
    {
        $registry = $this->registryWithScopedResolve();
        $worker = new ToolContext(principal: 'agent:worker', channel: 'mcp', scopes: ['tasks:write']);

        $request = $registry->call(VerificationTool::REQUEST_NAME, [
            'subject' => 'gate:report.publish',
        ], $worker);
        $requestId = $request->data['request_id'];

        $result = $registry->call(VerificationTool::RESOLVE_NAME, [
            'request_id' => $requestId,
            'decision' => 'grant',
            'principal' => 'agent:worker',
        ], $worker);

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::FORBIDDEN, $result->meta['code']);
        $this->assertStringContainsString('resolve_verification', $result->error);
        $this->assertStringContainsString('verification:resolve', $result->error);
    }

    public function testResolveVerificationAllowsAPrincipalWithTheResolveScope(): void
    {
        $registry = $this->registryWithScopedResolve();
        $worker = new ToolContext(principal: 'agent:worker', channel: 'mcp', scopes: ['tasks:write']);
        $reviewer = new ToolContext(principal: 'agent:reviewer', channel: 'mcp', scopes: ['tasks:write', 'verification:resolve']);

        $request = $registry->call(VerificationTool::REQUEST_NAME, [
            'subject' => 'gate:report.publish',
        ], $worker);
        $requestId = $request->data['request_id'];

        $result = $registry->call(VerificationTool::RESOLVE_NAME, [
            'request_id' => $requestId,
            'decision' => 'grant',
            'principal' => 'agent:reviewer',
        ], $reviewer);

        $this->assertTrue($result->success);
        $this->assertEquals('passed', $result->data['status']);
    }
}
