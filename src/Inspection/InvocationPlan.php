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

    /** @return array<string, mixed> */
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
