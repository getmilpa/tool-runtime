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

namespace Milpa\ToolRuntime\RateLimiting;

/**
 * In-memory rate limiter using sliding window algorithm.
 *
 * Note: This implementation is per-process. For production with multiple
 * workers, use RedisRateLimiter instead.
 */
class InMemoryRateLimiter implements RateLimiterInterface
{
    /**
     * Storage: key => ['tokens' => int, 'window_start' => int]
     *
     * @var array<string, array{tokens: int, window_start: int}>
     */
    private array $buckets = [];

    /**
     * Attempt to consume tokens from this key's in-memory sliding-window bucket.
     *
     * Lazily resets the bucket once its window has elapsed, then denies the call when consuming
     * would push usage past $maxTokens within the window; otherwise deducts $cost immediately.
     * Buckets live in a process-local array, so limits are not shared across workers/processes.
     */
    public function consume(
        string $key,
        int $cost = 1,
        int $windowSeconds = 60,
        int $maxTokens = 100
    ): RateLimitResult {
        $now = time();

        // Initialize or reset expired bucket
        if (!isset($this->buckets[$key]) ||
            ($now - $this->buckets[$key]['window_start']) >= $windowSeconds) {
            $this->buckets[$key] = [
                'tokens' => 0,
                'window_start' => $now,
            ];
        }

        $bucket = &$this->buckets[$key];
        $currentUsage = $bucket['tokens'];

        // Check if we can consume
        if ($currentUsage + $cost > $maxTokens) {
            $retryAfter = $windowSeconds - ($now - $bucket['window_start']);
            return RateLimitResult::denied(
                "Rate limit exceeded for '{$key}': max {$maxTokens} tokens per {$windowSeconds}s.",
                max(0, $retryAfter)
            );
        }

        // Consume tokens
        $bucket['tokens'] += $cost;

        return RateLimitResult::allowed($maxTokens - $bucket['tokens']);
    }

    /**
     * Read current token usage for a key without consuming any; an expired window reads as zero.
     */
    public function getUsage(string $key): int
    {
        if (!isset($this->buckets[$key])) {
            return 0;
        }

        // Check if window expired
        $windowSeconds = 60; // Default, could be configurable
        if ((time() - $this->buckets[$key]['window_start']) >= $windowSeconds) {
            return 0;
        }

        return $this->buckets[$key]['tokens'];
    }

    /**
     * Discard the bucket for a key, immediately restoring its full quota.
     */
    public function reset(string $key): void
    {
        unset($this->buckets[$key]);
    }

    /**
     * Cleanup expired buckets (call periodically to free memory).
     */
    public function cleanup(int $maxAge = 300): void
    {
        $now = time();
        foreach ($this->buckets as $key => $bucket) {
            if (($now - $bucket['window_start']) > $maxAge) {
                unset($this->buckets[$key]);
            }
        }
    }
}
