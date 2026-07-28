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
 * One station of the invocation pipeline, as it stands for this particular call.
 *
 * `presence` carries the judgement and the rest carries the consequences: whether it can stop the
 * call (`blocking`), whether it rewrites the arguments on the way through (`mutates`), and which
 * step it wraps when it is not a station of its own. `source` names what decided all of that, so a
 * reader can go argue with the right component rather than with the plan.
 */
final readonly class InvocationStep
{
    public function __construct(
        public InvocationStepKind $kind,
        public InvocationStepRole $role,
        public StepPresence $presence,
        public bool $blocking,
        public bool $mutates,
        public ?InvocationStepKind $wraps,
        public string $source,
    ) {
    }

    /**
     * Flattens the step, including the wrapped kind when there is one.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'role' => $this->role->value,
            'presence' => $this->presence->value,
            'blocking' => $this->blocking,
            'mutates' => $this->mutates,
            'wraps' => $this->wraps?->value,
            'source' => $this->source,
        ];
    }
}
