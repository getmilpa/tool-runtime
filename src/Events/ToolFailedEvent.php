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
 * POST event (tool-runtime 0.5, event-driven retrofit): a tool's callback threw.
 *
 * Dispatched by {@see \Milpa\ToolRuntime\ToolRegistry::call()} as `tool.failed` — pure
 * notification, readonly, no slot (an exception has already happened; there is nothing left to
 * veto or short-circuit). {@see \Milpa\ToolRuntime\ToolAuditLogger} subscribes to this event to
 * log the failure exactly as it did before this event existed.
 *
 * Only dispatched for an exception thrown by the tool's own callback during execution — NOT for
 * validation errors, authorization denials, or rate-limit rejections, which are rejected before
 * `tool.executing` is ever dispatched (see the security anchor on
 * {@see \Milpa\ToolRuntime\ToolRegistry::call()}) and continue to be logged directly by
 * {@see \Milpa\ToolRuntime\ToolAuditLogger::logValidationFailure()} /
 * {@see \Milpa\ToolRuntime\ToolAuditLogger::logAuthFailure()} / the rate-limit branch's direct
 * {@see \Milpa\ToolRuntime\ToolAuditLogger::log()} call.
 */
final readonly class ToolFailedEvent
{
    /**
     * @param string               $name      Tool name that failed
     * @param ToolContext          $ctx       Execution context the call ran under
     * @param array<string, mixed> $args      The (validated/clamped) arguments the call ran with
     * @param \Throwable           $exception The exception thrown by the tool's callback
     * @param int                  $tookMs    Elapsed time in milliseconds up to the failure
     */
    public function __construct(
        public string $name,
        public ToolContext $ctx,
        public array $args,
        public \Throwable $exception,
        public int $tookMs,
    ) {
    }
}
