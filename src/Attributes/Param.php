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
 * Describes a tool method parameter.
 * Used to generate JSON schema automatically.
 *
 * @example
 * public function search(
 *     #[Param('Search query')] string $query,
 *     #[Param('Max results', clamp: [1, 100])] int $limit = 20
 * ): ToolResult { ... }
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Param
{
    /**
     * @param list<int|float>|null $clamp
     * @param list<mixed>|null     $enum
     */
    public function __construct(
        public readonly string $description = '',
        public readonly mixed $default = null,
        public readonly ?array $clamp = null,
        public readonly ?array $enum = null,
        public readonly bool $required = false
    ) {
    }
}
