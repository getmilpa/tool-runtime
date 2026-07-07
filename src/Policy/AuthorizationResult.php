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

namespace Milpa\ToolRuntime\Policy;

/**
 * Result of authorization check.
 */
class AuthorizationResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null
    ) {
    }

    /**
     * Build a passing authorization result.
     */
    public static function allowed(): self
    {
        return new self(true);
    }

    /**
     * Build a failing authorization result carrying the human-readable reason for the denial.
     */
    public static function denied(string $reason): self
    {
        return new self(false, $reason);
    }
}
