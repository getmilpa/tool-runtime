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
 * @example Object-shaped parameter (tool-runtime 0.6)
 * A native PHP `array` has no type that maps to JSON-Schema `type: object` on its
 * own — {@see \Milpa\ToolRuntime\ToolScanner} infers `array` -> `type: array`, which
 * {@see \Milpa\ToolRuntime\SchemaValidator} then requires to be a list, rejecting an
 * associative payload like `{"post_id": 1}`. Pass `type: 'object'` to override the
 * inferred schema type for a PHP `array $param` — the wire value still arrives as a
 * plain associative `array` (the host's JSON decode already produced it; no manual
 * `json_decode()` is needed in the tool body):
 * public function updatePost(
 *     int $post_id,
 *     #[Param('Fields to update', type: 'object', properties: [
 *         'title' => ['type' => 'string'],
 *         'body' => ['type' => 'string'],
 *     ])]
 *     array $updates
 * ): ToolResult { ... }
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Param
{
    /**
     * @param list<int|float>|null      $clamp
     * @param list<mixed>|null          $enum
     * @param string|null               $type               JSON-Schema `type` override for this
     *                                                      parameter (e.g. `'object'`). When
     *                                                      omitted, the type is inferred from
     *                                                      the PHP parameter's native type exactly
     *                                                      as before (tool-runtime 0.6, additive).
     * @param array<string, mixed>|null $properties         Nested JSON-Schema `properties` map,
     *                                                      meaningful when `type` is `'object'`.
     *                                                      Omitted entirely from the generated
     *                                                      schema when `null` (an open object with
     *                                                      no declared shape).
     * @param list<string>|null         $requiredProperties Nested JSON-Schema `required` list for
     *                                                      the object's own `properties` (sibling
     *                                                      of `properties`, not to be confused with
     *                                                      `$required` below, which marks the
     *                                                      parameter ITSELF required in the outer
     *                                                      schema).
     */
    public function __construct(
        public readonly string $description = '',
        public readonly mixed $default = null,
        public readonly ?array $clamp = null,
        public readonly ?array $enum = null,
        public readonly bool $required = false,
        public readonly ?string $type = null,
        public readonly ?array $properties = null,
        public readonly ?array $requiredProperties = null
    ) {
    }
}
