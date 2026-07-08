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

namespace Milpa\ToolRuntime\Contracts;

/**
 * Context for tool execution.
 *
 * Contains information about who/where/what permissions for authorization.
 */
class ToolContext
{
    public readonly string $request_id;

    /**
     * Execution mode: 'execute' (default) or 'plan' (dry-run).
     */
    public readonly string $mode;

    /**
     * @param array<string>        $scopes
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly ?string $principal = null,    // user_id or service name
        public readonly string $channel = 'unknown',  // telegram, web, cli, mcp
        public readonly array $scopes = [],           // e.g., ['notes:read', 'notes:write']
        ?string $request_id = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly array $extra = [],            // additional context data
        string $mode = 'execute'                      // 'execute' | 'plan'
    ) {
        // Auto-generate request ID if not provided
        $this->request_id = $request_id ?? ToolMeta::generateRequestId();
        $this->mode = in_array($mode, ['execute', 'plan'], true) ? $mode : 'execute';
    }

    /**
     * Clone this context in plan mode (dry-run).
     * Plan mode validates and authorizes but does NOT execute the tool callback.
     */
    public function asPlan(): self
    {
        return new self(
            principal: $this->principal,
            channel: $this->channel,
            scopes: $this->scopes,
            request_id: $this->request_id,
            ip: $this->ip,
            userAgent: $this->userAgent,
            extra: $this->extra,
            mode: 'plan',
        );
    }

    /**
     * Check if context is in plan mode.
     */
    public function isPlanMode(): bool
    {
        return $this->mode === 'plan';
    }

    /**
     * Check if context is in execute mode.
     */
    public function isExecuteMode(): bool
    {
        return $this->mode === 'execute';
    }

    /**
     * Check if context has a specific scope.
     */
    public function hasScope(string $scope): bool
    {
        // Wildcard admin scope
        if (in_array('*', $this->scopes, true)) {
            return true;
        }
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Check if context has any of the given scopes.
     *
     * @param array<string> $scopes
     */
    public function hasAnyScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope($scope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if context has all of the given scopes.
     *
     * @param array<string> $scopes
     */
    public function hasAllScopes(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!$this->hasScope($scope)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create context for CLI usage (full access).
     *
     * @param string|null $requestId Optional request ID
     * @param string      $mode      Execution mode: 'execute' or 'plan'
     */
    public static function cli(?string $requestId = null, string $mode = 'execute'): self
    {
        return new self(
            principal: 'cli',
            channel: 'cli',
            scopes: ['*'],
            request_id: $requestId ?? ToolMeta::generateRequestId(),
            mode: $mode
        );
    }

    /**
     * Create context for a trusted local MCP stdio server process.
     *
     * ⚠️ **PROCESS-LEVEL TRUST.** This hard-codes `principal: 'stdio'` and the wildcard
     * `['*']` scope by default — exactly the same "no real auth, but the channel police
     * accepts a hard-coded identity" shape {@see cli()} already uses for CLI scripts. It is
     * only appropriate for a transport where the OS process boundary IS the authentication
     * boundary: a local stdio MCP server that trusts whatever spawned it (e.g. an editor or
     * agent runtime launching the binary as a child process), with no separate per-caller
     * identity to authenticate over the wire. Do NOT use this for any MCP transport exposed
     * over a network (HTTP+SSE, WebSocket, a shared multi-tenant socket, ...) where distinct
     * callers are NOT process-trusted — build a {@see mcp()} context per authenticated caller
     * instead.
     *
     * Exists because the `mcp` channel's built-in policy sets `require_auth: true` (see
     * {@see \Milpa\ToolRuntime\PolicyGate}), so a bare `new ToolContext(channel: 'mcp')` (no
     * `principal`) denies every call with "channel 'mcp' requires an authenticated principal" —
     * the exact trap a no-auth stdio server falls into with no documented way out. `stdio()`
     * is that documented way out, in one call instead of hand-rolling the same `principal:
     * 'stdio', scopes: ['*']` workaround.
     *
     * @param string        $requestId The MCP request ID
     * @param string        $principal Opaque principal recorded for audit/logging — defaults
     *                                 to `'stdio'` since there is no real caller to authenticate
     * @param array<string> $scopes    Scopes granted to this context — defaults to `['*']`
     *                                 (full access), matching the process-level trust model
     */
    public static function stdio(string $requestId, string $principal = 'stdio', array $scopes = ['*']): self
    {
        return new self(
            principal: $principal,
            channel: 'mcp',
            scopes: $scopes,
            request_id: $requestId,
        );
    }

    /**
     * Create context for MCP server.
     *
     * @param string        $requestId The MCP request ID
     * @param string|null   $principal The authenticated principal (user/service)
     * @param array<string> $scopes    Explicit scopes granted to this context
     * @param string        $mode      Execution mode: 'execute' or 'plan'
     *
     * @deprecated Calling without explicit scopes is deprecated and will be removed in v2.0
     */
    public static function mcp(string $requestId, ?string $principal = null, array $scopes = [], string $mode = 'execute'): self
    {
        // Deprecation warning if called without explicit scopes
        if (empty($scopes)) {
            @trigger_error(
                'ToolContext::mcp() sin scopes explícitos está deprecado. ' .
                'Pase scopes como tercer parámetro. Será removido en v2.0.',
                E_USER_DEPRECATED
            );
        }

        return new self(
            principal: $principal ?? 'mcp',
            channel: 'mcp',
            scopes: $scopes,  // No more wildcard ['*'] by default
            request_id: $requestId,
            mode: $mode
        );
    }

    /**
     * Create context for Telegram.
     *
     * @param string      $chatId Telegram chat ID
     * @param string|null $userId Telegram user ID
     * @param string      $mode   Execution mode: 'execute' or 'plan'
     */
    public static function telegram(string $chatId, ?string $userId = null, string $mode = 'execute'): self
    {
        return new self(
            principal: $userId ?? "chat:{$chatId}",
            channel: 'telegram',
            scopes: ['read', 'write'], // Default Telegram scopes
            request_id: ToolMeta::generateRequestId(),
            extra: ['chat_id' => $chatId],
            mode: $mode
        );
    }

    /**
     * Serialize this context to a plain array for logging or transport.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'principal' => $this->principal,
            'channel' => $this->channel,
            'scopes' => $this->scopes,
            'request_id' => $this->request_id,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'mode' => $this->mode,
        ];
    }
}
