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

namespace Milpa\ToolRuntime\Attributes;

use Attribute;

/**
 * Marks a method as an AI-callable tool.
 * The ToolRegistry will auto-discover these methods.
 *
 * @example
 * #[Tool('list_notes', 'List saved notes', scopes: ['notes:read'])]
 * public function listNotes(int $page = 1): ToolResult { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Tool
{
    /**
     * @param array<string>                   $scopes
     * @param array<string, array<int|float>> $clamps
     * @param array<string, mixed>|null       $outputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $scopes = [],
        public readonly bool $confirm = false,
        public readonly array $clamps = [],
        public readonly ?string $category = null,
        public readonly ?string $version = null,
        public readonly ?array $outputSchema = null
    ) {
    }
}
