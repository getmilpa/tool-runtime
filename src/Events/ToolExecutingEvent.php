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

namespace Milpa\ToolRuntime\Events;

use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * PRE event (tool-runtime 0.5, event-driven retrofit): a tool is about to execute.
 *
 * Dispatched by {@see \Milpa\ToolRuntime\ToolRegistry::call()} as `tool.executing`, ALWAYS
 * alongside a `Milpa\Events\InterceptionSlot` in the same payload — this is the ONE event in
 * this package's catalog that carries a slot, because it is the ONE point where a plugin may
 * legitimately answer on the tool's behalf (a cache short-circuit) or veto the call outright.
 *
 * **Security anchor (non-negotiable).** This event is dispatched strictly AFTER resolve,
 * validate/clamp, {@see \Milpa\ToolRuntime\PolicyGate::authorize()}, rate-limiting, and the
 * confirm-gate have ALL already run and ALL already passed — never before. A listener subscribed
 * to `tool.executing` (e.g. a cache plugin) only ever gets a turn once every gate has said yes;
 * an unauthorized/rate-limited/unconfirmed call never reaches this event at all, so a cache
 * short-circuit can never be mistaken for an authorization bypass. See
 * {@see \Milpa\ToolRuntime\ToolRegistry::call()} for the exact placement.
 *
 * Readonly like every event VO in the family — the mutable escape hatch for short-circuit/veto
 * lives entirely in the {@see \Milpa\Events\InterceptionSlot} dispatched alongside this event,
 * never on the event itself.
 */
final readonly class ToolExecutingEvent
{
    /**
     * @param string               $name Tool name being called
     * @param ToolContext          $ctx  Execution context (principal, channel, scopes, ...)
     * @param array<string, mixed> $args Validated and clamped arguments about to be passed to
     *                                   the tool's callback (does not yet carry the internal
     *                                   `_ctx` key — that is injected only if execution proceeds)
     */
    public function __construct(
        public string $name,
        public ToolContext $ctx,
        public array $args,
    ) {
    }
}
