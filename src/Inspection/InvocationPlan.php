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

namespace Milpa\ToolRuntime\Inspection;

/**
 * What would happen if this tool were invoked with this context — decided without invoking it.
 *
 * Answers the question a dry-run cannot: not "did it work" but "which guards stand between the
 * call and the effect, which of them are live right now, and which are present but dormant". The
 * plan is the artifact `coa` renders when someone asks why a call was refused, or why one they
 * expected to be refused went through.
 *
 * `schemaVersion` is here because this shape is consumed by tooling outside the process; changing
 * a field without bumping it breaks a reader that has no way to notice.
 */
final readonly class InvocationPlan
{
    /**
     * @param array{actor: ?string, scopes: list<string>, mode: string}            $context
     * @param list<string>                                                         $assumptions
     * @param array{rateLimiter: string, dispatcher: string, ruleProvider: string} $wiring
     * @param list<InvocationStep>                                                 $steps
     */
    public function __construct(
        public string $schemaVersion,
        public string $operation,
        public string $channel,
        public array $context,
        public array $assumptions,
        public array $wiring,
        public array $steps,
    ) {
    }

    /**
     * Flattens the plan for whatever is going to render or ship it.
     *
     * Enums become their string values here rather than at the edges, so a JSON reader and a
     * terminal renderer cannot disagree about what a step is called.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'operation' => $this->operation,
            'channel' => $this->channel,
            'context' => $this->context,
            'assumptions' => $this->assumptions,
            'wiring' => $this->wiring,
            'steps' => array_map(static fn (InvocationStep $s): array => $s->toArray(), $this->steps),
        ];
    }
}
