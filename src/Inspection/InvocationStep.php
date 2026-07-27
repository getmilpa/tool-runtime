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

    /** @return array<string, mixed> */
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
