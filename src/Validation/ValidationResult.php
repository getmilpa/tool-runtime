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

namespace Milpa\ToolRuntime\Validation;

/**
 * Result of schema validation.
 */
class ValidationResult
{
    /** @param list<string> $errors */
    private function __construct(
        public readonly bool $valid,
        public readonly array $errors
    ) {
    }

    /**
     * Build a passing validation result with no errors.
     */
    public static function success(): self
    {
        return new self(true, []);
    }

    /**
     * Build a failing validation result carrying the schema violations found.
     *
     * @param list<string> $errors
     */
    public static function failure(array $errors): self
    {
        return new self(false, $errors);
    }

    /**
     * Join all validation errors into a single semicolon-separated message.
     */
    public function getErrorMessage(): string
    {
        return implode('; ', $this->errors);
    }
}
