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

namespace Milpa\ToolRuntime\Events;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;

/**
 * POST event (tool-runtime 0.5, event-driven retrofit): a tool call finished, successfully or
 * via a cache short-circuit.
 *
 * Dispatched by {@see \Milpa\ToolRuntime\ToolRegistry::call()} as `tool.executed` — pure
 * notification, readonly, no slot (per the family's pre/post convention: `*ing` events are
 * stoppable, `*ed` events are audit-only). {@see \Milpa\ToolRuntime\ToolAuditLogger} subscribes
 * to this event to log every call; other listeners (metrics, tracing, ...) may subscribe
 * alongside it without touching {@see \Milpa\ToolRuntime\ToolRegistry}.
 *
 * **Cache-hit visibility invariant.** When a `tool.executing` listener short-circuits via
 * `InterceptionSlot::shortCircuit()`, the tool's real callback never runs — but this event MUST
 * still fire, with {@see $cacheServed} `true`. A cache hit that is invisible to `tool.executed`
 * listeners (audit, metrics) is a blind spot, not an optimization; see
 * {@see \Milpa\ToolRuntime\ToolRegistry::call()} for where this is guaranteed.
 */
final readonly class ToolExecutedEvent
{
    /**
     * @param string               $name        Tool name that was called
     * @param ToolContext          $ctx         Execution context the call ran under
     * @param array<string, mixed> $args        The (validated/clamped) arguments the call ran with
     * @param ToolResult           $result      The result returned to the caller
     * @param bool                 $cacheServed True if a `tool.executing` listener short-circuited
     *                                          the call (the real callback never ran) and this
     *                                          result came from that listener instead
     */
    public function __construct(
        public string $name,
        public ToolContext $ctx,
        public array $args,
        public ToolResult $result,
        public bool $cacheServed = false,
    ) {
    }
}
