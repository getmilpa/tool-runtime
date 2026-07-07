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
