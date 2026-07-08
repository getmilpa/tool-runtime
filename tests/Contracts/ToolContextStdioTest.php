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

namespace Milpa\ToolRuntime\Tests\Contracts;

use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Pins {@see ToolContext::stdio()} — the trusted-local-process factory for a stdio MCP
 * server, mirroring {@see ToolContext::cli()}'s "no real auth, but the channel police
 * accepts a hard-coded identity" shape. Closes the gap the DX friction log recorded: a
 * stdio MCP host had no documented recipe for a no-auth, process-trust `ToolContext` and
 * fell through `PolicyGate`'s `mcp` channel's `require_auth` denial by constructing a bare
 * `new ToolContext(channel: 'mcp')` (principal defaults to `null`) instead.
 */
final class ToolContextStdioTest extends TestCase
{
    public function testStdioDefaultsToStdioPrincipalWildcardScopeAndMcpChannel(): void
    {
        $ctx = ToolContext::stdio('req-1');

        $this->assertSame('stdio', $ctx->principal);
        $this->assertSame('mcp', $ctx->channel);
        $this->assertSame(['*'], $ctx->scopes);
        $this->assertSame('req-1', $ctx->request_id);
        $this->assertSame('execute', $ctx->mode);
    }

    public function testStdioAcceptsCustomPrincipalAndScopes(): void
    {
        $ctx = ToolContext::stdio('req-2', 'my-stdio-server', ['tools:read']);

        $this->assertSame('my-stdio-server', $ctx->principal);
        $this->assertSame(['tools:read'], $ctx->scopes);
        $this->assertSame('mcp', $ctx->channel);
    }

    public function testStdioHasWildcardScopeSoItPassesAnyToolScopeCheck(): void
    {
        $ctx = ToolContext::stdio('req-3');

        $this->assertTrue($ctx->hasScope('anything:at:all'));
    }
}
