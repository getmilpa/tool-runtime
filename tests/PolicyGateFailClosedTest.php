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

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Captures whatever is logged so a test can assert the learnable warning fired.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }
}

/**
 * Pins the fail-closed turn: an unknown (unregistered) channel used to inherit the laxest possible
 * policy — the old `?? []` meant `require_auth` defaulted false, so an anonymous caller on a channel
 * nobody registered sailed through. That is the one outcome a security-bearing gate must never reach.
 * Now an unregistered channel falls back to a FAIL-CLOSED policy (`require_auth: true`, no `allow_all`)
 * and the gate emits a learnable warning naming the channel that was never registered.
 */
final class PolicyGateFailClosedTest extends TestCase
{
    private function tool(string $name = 'test_tool', array $scopes = []): ToolDefinition
    {
        return new ToolDefinition(
            name: $name,
            description: 'Test tool',
            inputSchema: [],
            callback: static fn (): null => null,
            scopes: $scopes,
        );
    }

    public function testUnknownChannelDeniesAnUnauthenticatedCaller(): void
    {
        $gate = new PolicyGate();
        // Anonymous (no principal) on a channel nobody registered. Old behavior: allowed (fail-open).
        $ctx = new ToolContext(principal: null, channel: 'mystery-channel');

        $result = $gate->authorize($ctx, $this->tool());

        $this->assertFalse($result->allowed, 'an unknown channel must fail closed for an anonymous caller');
        $this->assertStringContainsString("channel 'mystery-channel'", (string) $result->reason);
        $this->assertStringContainsString('require_auth', (string) $result->reason);
    }

    public function testUnknownChannelEmitsALearnableWarning(): void
    {
        $logger = new SpyLogger();
        $gate = new PolicyGate($logger);
        $ctx = new ToolContext(principal: 'user:1', channel: 'mystery-channel', scopes: ['*']);

        $gate->authorize($ctx, $this->tool());

        $this->assertNotEmpty($logger->records, 'an unregistered channel must emit a learnable warning');
        $this->assertStringContainsString('mystery-channel', $logger->records[0]['message']);
        $this->assertStringContainsString('fail-closed', strtolower($logger->records[0]['message']));
    }

    public function testKnownChannelDoesNotWarn(): void
    {
        $logger = new SpyLogger();
        $gate = new PolicyGate($logger);
        $ctx = ToolContext::web('user:1', ['posts:read']);

        $gate->authorize($ctx, $this->tool(scopes: ['posts:read']));

        $this->assertSame([], $logger->records, 'a registered channel must never warn');
    }
}
