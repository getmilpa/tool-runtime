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

namespace Milpa\ToolRuntime;

/**
 * Tool definition with metadata.
 */
class ToolDefinition
{
    /**
     * @param array<string, mixed>            $inputSchema
     * @param array<string>                   $scopes
     * @param array<string, array<int|float>> $clamps
     * @param array<string, mixed>|null       $outputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly mixed $callback,
        public readonly array $scopes = [],
        public readonly bool $mutating = false,
        public readonly bool $requiresConfirmation = false,
        public readonly int $timeout = 30,
        public readonly array $clamps = [],
        public readonly ?string $version = null,
        public readonly ?array $outputSchema = null
    ) {
    }
}
