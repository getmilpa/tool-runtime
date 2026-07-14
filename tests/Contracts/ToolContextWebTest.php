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

namespace Milpa\ToolRuntime\Tests\Contracts;

use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Pins {@see ToolContext::web()} — the factory for an authenticated HTTP caller. Where
 * {@see ToolContext::cli()}/{@see ToolContext::stdio()} encode process-level trust with a blanket
 * `['*']`, `web()` is the end of that faked wildcard on the web surface: it carries the EXACT scopes
 * the host verified, never a wildcard. It takes primitives (a principal id + a scope list) rather
 * than a milpa/auth `Actor`, keeping tool-runtime a leaf — the host that owns the `Actor` maps it in.
 */
final class ToolContextWebTest extends TestCase
{
    public function testWebPopulatesRealPrincipalRealScopesAndTheWebChannel(): void
    {
        $ctx = ToolContext::web('user:42', ['posts:read', 'posts:write']);

        $this->assertSame('user:42', $ctx->principal);
        $this->assertSame('web', $ctx->channel);
        $this->assertSame(['posts:read', 'posts:write'], $ctx->scopes);
        $this->assertSame('execute', $ctx->mode);
    }

    public function testWebNeverFakesAWildcardScope(): void
    {
        $ctx = ToolContext::web('user:1', ['posts:read']);

        // The whole point: a web caller holds exactly what the host verified — no magic '*'.
        $this->assertNotSame(['*'], $ctx->scopes);
        $this->assertTrue($ctx->hasScope('posts:read'));
        $this->assertFalse($ctx->hasScope('admin:everything'));
    }

    public function testWebWithNoScopesGrantsNothing(): void
    {
        $ctx = ToolContext::web('user:1', []);

        $this->assertSame([], $ctx->scopes);
        $this->assertFalse($ctx->hasScope('posts:read'));
    }
}
