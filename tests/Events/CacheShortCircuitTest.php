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

namespace Milpa\ToolRuntime\Tests\Events;

use Milpa\Eventing\EventDispatcher;
use Milpa\Events\InterceptionSlot;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Events\ToolExecutedEvent;
use Milpa\ToolRuntime\Events\ToolExecutingEvent;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE LIVE PROOF (tool-runtime 0.5, event-driven retrofit's Done-when): a cache plugin subscribes
 * to `tool.executing` and short-circuits via {@see InterceptionSlot::shortCircuit()} through the
 * REAL {@see ToolRegistry::call()} pipeline and a REAL {@see EventDispatcher} — no mocking of the
 * dispatch mechanics — because the point being proven is that Open/Closed actually holds end to
 * end, not just that the wiring compiles.
 *
 * Three assertions, matching the wave's Done-when exactly:
 *  (a) the tool's real callback NEVER runs on a cache hit;
 *  (b) `tool.executed` still fires, marked `cacheServed: true`, on that cache hit — a cache hit is
 *      never invisible to audit;
 *  (c) THE SECURITY ASSERTION — a call that fails {@see \Milpa\ToolRuntime\PolicyGate::authorize()}
 *      never reaches the cache listener at all: the anchor in {@see ToolRegistry::call()} sits
 *      strictly after authorize()/rate-limit/confirm-gate, so a denied principal gets a denial,
 *      never a cache hit, no matter that a cache plugin is subscribed to `tool.executing`.
 */
class CacheShortCircuitTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private ToolRegistry $registry;

    /** Number of times the tool's real callback ran. */
    private int $callbackRuns = 0;

    /** Number of times the cache listener on `tool.executing` was invoked. */
    private int $cacheListenerInvocations = 0;

    /** @var list<ToolExecutedEvent> Every `tool.executed` event observed. */
    private array $executedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $logger = new NullLogger();
        $this->dispatcher = new EventDispatcher($logger);
        $this->registry = new ToolRegistry($logger, $this->dispatcher);

        $this->registry->register(
            'get_report',
            'Fetch a report (expensive — the thing a cache plugin wants to short-circuit)',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            function (array $args): ToolResult {
                $this->callbackRuns++;

                return ToolResult::success(['id' => $args['id'], 'source' => 'live']);
            },
            new ToolOptions(scopes: ['reports:read'])
        );

        // The cache plugin: the README's recipe, live. Subscribes to tool.executing and answers
        // on the tool's behalf via InterceptionSlot::shortCircuit() — the tool never runs.
        $this->dispatcher->subscribe('tool.executing', function (string $eventName, array $payload): void {
            $this->cacheListenerInvocations++;

            /** @var ToolExecutingEvent $event */
            $event = $payload['event'];
            /** @var InterceptionSlot $slot */
            $slot = $payload['slot'];

            $slot->shortCircuit(ToolResult::success(['id' => $event->args['id'], 'source' => 'cache']));
        });

        // Observability: records every tool.executed for assertions (a)/(b)/(c) below.
        $this->dispatcher->subscribe('tool.executed', function (string $eventName, array $payload): void {
            $this->executedEvents[] = $payload['event'];
        });
    }

    public function testCacheHitShortCircuitsAndSkipsTheRealCallback(): void
    {
        $ctx = new ToolContext(principal: 'user:1', channel: 'web', scopes: ['reports:read']);

        $result = $this->registry->call('get_report', ['id' => 42], $ctx);

        // (a) the tool's real callback NEVER ran.
        $this->assertSame(0, $this->callbackRuns);

        // The cache plugin answered instead of the tool.
        $this->assertSame(1, $this->cacheListenerInvocations);
        $this->assertTrue($result->success);
        $this->assertSame('cache', $result->data['source']);
        $this->assertSame(42, $result->data['id']);

        // (b) tool.executed still fired, marked cacheServed: true — a cache hit is never
        // invisible to `tool.executed` listeners (audit/metrics).
        $this->assertCount(1, $this->executedEvents);
        $this->assertSame('get_report', $this->executedEvents[0]->name);
        $this->assertTrue($this->executedEvents[0]->cacheServed);
        $this->assertSame($result, $this->executedEvents[0]->result);
    }

    public function testUnauthorizedCallNeverReachesTheCacheListener(): void
    {
        // No 'reports:read' scope — PolicyGate::authorize() denies this BEFORE the anchor.
        $deniedCtx = new ToolContext(principal: 'user:2', channel: 'web', scopes: []);

        $result = $this->registry->call('get_report', ['id' => 42], $deniedCtx);

        // Denied — not a cache hit, not the live result either.
        $this->assertFalse($result->success);
        $this->assertSame(ToolResult::FORBIDDEN, $result->meta['code']);

        // (c) THE SECURITY ASSERTION: the cache listener never even ran for this call. A cache
        // plugin being wired does not, and must not, grant access authorize() denied.
        $this->assertSame(0, $this->cacheListenerInvocations);
        $this->assertSame(0, $this->callbackRuns);
        $this->assertCount(0, $this->executedEvents);
    }

    public function testAuthorizedCallWithNoShortCircuitRunsTheRealCallbackNormally(): void
    {
        // Sanity check: tool.executing fires (the anchor is live) but nobody short-circuits or
        // vetoes — execution proceeds exactly as it did before this retrofit.
        $ctx = new ToolContext(principal: 'user:3', channel: 'mcp', scopes: ['reports:read']);

        // A registry with the SAME dispatcher but no cache subscriber, to isolate this case.
        $dispatcher = new EventDispatcher(new NullLogger());
        $registry = new ToolRegistry(new NullLogger(), $dispatcher);
        $callbackRuns = 0;
        $registry->register(
            'get_report',
            'Fetch a report',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            function (array $args) use (&$callbackRuns): ToolResult {
                $callbackRuns++;

                return ToolResult::success(['id' => $args['id'], 'source' => 'live']);
            },
            new ToolOptions(scopes: ['reports:read'])
        );

        $executed = [];
        $dispatcher->subscribe('tool.executed', function (string $eventName, array $payload) use (&$executed): void {
            $executed[] = $payload['event'];
        });

        $result = $registry->call('get_report', ['id' => 7], $ctx);

        $this->assertSame(1, $callbackRuns);
        $this->assertTrue($result->success);
        $this->assertSame('live', $result->data['source']);
        $this->assertCount(1, $executed);
        $this->assertFalse($executed[0]->cacheServed);
    }
}
