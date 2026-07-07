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

namespace Milpa\ToolRuntime;

use Milpa\ToolRuntime\Confirmation\ConfirmationToken;

/**
 * Stores pending confirmation tokens for destructive operations.
 */
class ConfirmationTokenStore
{
    /**
     * In-memory store for tokens.
     * In production, use Redis/APCu.
     *
     * @var array<string, array{tool: string, args: array<string, mixed>, expires_at: int, action_summary: string}>
     */
    private array $tokens = [];

    /**
     * Default TTL in seconds.
     */
    private int $ttl = 60;

    /**
     * Generate and store a confirmation token.
     *
     * @param array<string, mixed> $args
     */
    public function create(string $tool, array $args, string $actionSummary): ConfirmationToken
    {
        $token = bin2hex(random_bytes(16));
        $expiresAt = time() + $this->ttl;

        $this->tokens[$token] = [
            'tool' => $tool,
            'args' => $args,
            'expires_at' => $expiresAt,
            'action_summary' => $actionSummary,
        ];

        // Cleanup expired tokens
        $this->cleanup();

        return new ConfirmationToken(
            token: $token,
            expiresAt: new \DateTimeImmutable("@{$expiresAt}"),
            actionSummary: $actionSummary
        );
    }

    /**
     * Validate and consume a confirmation token.
     *
     * Returns the original args if valid, null if invalid/expired.
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $token, string $tool): ?array
    {
        if (!isset($this->tokens[$token])) {
            return null;
        }

        $stored = $this->tokens[$token];

        // Check expiration
        if (time() > $stored['expires_at']) {
            unset($this->tokens[$token]);
            return null;
        }

        // Check tool matches
        if ($stored['tool'] !== $tool) {
            return null;
        }

        // Consume (one-time use)
        $args = $stored['args'];
        unset($this->tokens[$token]);

        return $args;
    }

    /**
     * Check if a token is valid (without consuming).
     */
    public function isValid(string $token, string $tool): bool
    {
        if (!isset($this->tokens[$token])) {
            return false;
        }

        $stored = $this->tokens[$token];

        return time() <= $stored['expires_at'] && $stored['tool'] === $tool;
    }

    /**
     * Cleanup expired tokens.
     */
    private function cleanup(): void
    {
        $now = time();
        foreach ($this->tokens as $token => $data) {
            if ($now > $data['expires_at']) {
                unset($this->tokens[$token]);
            }
        }
    }

    /**
     * Set TTL for new tokens.
     */
    public function setTtl(int $seconds): void
    {
        $this->ttl = $seconds;
    }
}
