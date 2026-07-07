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

namespace Milpa\ToolRuntime\RateLimiting;

/**
 * Result of a rate limit check.
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?int $remainingTokens = null,
    ) {
    }

    /**
     * Build a result permitting the call, reporting how many tokens remain in the current window.
     */
    public static function allowed(int $remainingTokens = 0): self
    {
        return new self(allowed: true, remainingTokens: $remainingTokens);
    }

    /**
     * Build a result rejecting the call, with a reason and a suggested retry delay.
     */
    public static function denied(string $reason, int $retryAfterSeconds = 0): self
    {
        return new self(
            allowed: false,
            reason: $reason,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }
}
