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
 * Interface for rate limiters.
 */
interface RateLimiterInterface
{
    /**
     * Attempt to consume tokens from the rate limit bucket.
     *
     * @param string $key           Unique key for rate limiting (e.g., "mcp:user123:tool_name")
     * @param int    $cost          Number of tokens to consume (default: 1)
     * @param int    $windowSeconds Time window in seconds (default: 60)
     * @param int    $maxTokens     Maximum tokens allowed in window (default: 100)
     *
     * @return RateLimitResult
     */
    public function consume(
        string $key,
        int $cost = 1,
        int $windowSeconds = 60,
        int $maxTokens = 100
    ): RateLimitResult;

    /**
     * Get current usage for a key without consuming.
     */
    public function getUsage(string $key): int;

    /**
     * Reset rate limit for a key.
     */
    public function reset(string $key): void;
}
