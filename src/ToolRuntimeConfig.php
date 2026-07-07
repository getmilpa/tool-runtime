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

/**
 * Configuration constants for ToolRuntime.
 *
 * Centralizes default values for rate limiting, timeouts, and other runtime settings.
 */
final class ToolRuntimeConfig
{
    // ========== Rate Limiting Defaults ==========

    /**
     * Default window size for rate limiting in seconds.
     */
    public const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Default maximum tokens per window.
     */
    public const RATE_LIMIT_MAX_TOKENS = 100;

    /**
     * Token cost for read operations.
     */
    public const RATE_LIMIT_COST_READ = 1;

    /**
     * Token cost for mutating operations.
     */
    public const RATE_LIMIT_COST_MUTATING = 5;

    // ========== Timeout Defaults ==========

    /**
     * Default timeout for tool execution in seconds.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    // ========== Cache Settings ==========

    /**
     * TTL for policy rule cache in seconds.
     */
    public const POLICY_CACHE_TTL_SECONDS = 60;

    // ========== Confirmation Token Settings ==========

    /**
     * TTL for confirmation tokens in seconds.
     */
    public const CONFIRMATION_TOKEN_TTL_SECONDS = 60;

    // ========== Audit Settings ==========

    /**
     * Fields to completely exclude from audit logs.
     */
    public const AUDIT_EXCLUDED_FIELDS = ['_ctx'];

    /**
     * Fields to sanitize (mask) in audit logs.
     */
    public const AUDIT_SENSITIVE_FIELDS = ['password', 'token', 'secret', 'api_key', 'apiKey'];
}
