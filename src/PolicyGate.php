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

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Contracts\PolicyRuleProviderInterface;
use Milpa\ToolRuntime\Policy\AuthorizationResult;

/**
 * Policy gate for tool authorization.
 *
 * Checks scopes, channel policies, and tool permissions.
 * Supports dynamic rules via PolicyRuleProviderInterface (e.g. the host's Doctrine-backed provider).
 */
class PolicyGate
{
    /**
     * Optional provider for database-driven policy rules.
     */
    private ?PolicyRuleProviderInterface $ruleProvider = null;

    /**
     * Channel-specific policies (fallback when no DB rules match).
     *
     * @var array<string, array<string, mixed>>
     */
    private array $channelPolicies = [
        'cli' => [
            'allow_all' => true,
        ],
        'mcp' => [
            'allow_all' => false,  // Security: MCP must validate scopes
            'require_auth' => true,
        ],
        'telegram' => [
            'allow_all' => false,
            'block_mutating' => false,  // Allow mutating operations on telegram
            'require_confirmation_for_mutating' => true,  // But require confirmation
        ],
        'web' => [
            'allow_all' => false,
            'require_auth' => true,
        ],
    ];

    /**
     * Set the policy rule provider for database-driven rules.
     */
    public function setRuleProvider(PolicyRuleProviderInterface $provider): void
    {
        $this->ruleProvider = $provider;
    }

    /**
     * Get the current rule provider (if set).
     */
    public function getRuleProvider(): ?PolicyRuleProviderInterface
    {
        return $this->ruleProvider;
    }

    /**
     * Authorize a tool call.
     *
     * @param ToolContext    $ctx  The execution context
     * @param ToolDefinition $tool The tool being called
     *
     * @return AuthorizationResult
     */
    public function authorize(ToolContext $ctx, ToolDefinition $tool): AuthorizationResult
    {
        $policy = $this->channelPolicies[$ctx->channel] ?? [];

        // 1. Check require_auth first - if channel requires auth, principal must be set
        if (($policy['require_auth'] ?? false) && empty($ctx->principal)) {
            return AuthorizationResult::denied(
                "Authentication required for channel: {$ctx->channel}"
            );
        }

        // 2. Check if tool requires specific scopes
        if (!empty($tool->scopes)) {
            if (!$ctx->hasAnyScope($tool->scopes)) {
                return AuthorizationResult::denied(
                    "Missing required scope. Need one of: " . implode(', ', $tool->scopes)
                );
            }
        }

        // 3. Check DB rules if repository available
        if ($this->ruleProvider !== null) {
            $dbResult = $this->checkDatabaseRules($ctx, $tool);
            if ($dbResult !== null) {
                return $dbResult;
            }
        }

        // 4. Fallback to channel policies
        $channelResult = $this->checkChannelPolicy($ctx->channel, $tool, $policy);
        if (!$channelResult->allowed) {
            return $channelResult;
        }

        return AuthorizationResult::allowed();
    }

    /**
     * Check database rules for authorization.
     *
     * @return AuthorizationResult|null Returns null if no matching rule found (use fallback)
     */
    private function checkDatabaseRules(ToolContext $ctx, ToolDefinition $tool): ?AuthorizationResult
    {
        if ($this->ruleProvider === null) {
            return null;
        }

        $rule = $this->ruleProvider->findMatchingRule(
            $ctx->channel,
            $ctx->principal,
            $tool->name,
            $tool->mutating
        );

        if ($rule === null) {
            return null; // No matching rule, use fallback
        }

        // Check additional scope requirements from rule
        $requiredScopes = $rule->getRequiresScopes();
        if ($requiredScopes !== null && !empty($requiredScopes)) {
            if (!$ctx->hasAnyScope($requiredScopes)) {
                return AuthorizationResult::denied(
                    "Rule requires scope: " . implode(', ', $requiredScopes)
                );
            }
        }

        if ($rule->getEffect() === 'allow') {
            return AuthorizationResult::allowed();
        }

        return AuthorizationResult::denied(
            $rule->getDescription() ?? "Denied by policy rule #{$rule->getId()}"
        );
    }

    /**
     * Check channel-specific policies.
     *
     * @param string               $channel The channel name
     * @param ToolDefinition       $tool    The tool being called
     * @param array<string, mixed> $policy  Pre-loaded policy (optional, for optimization)
     */
    private function checkChannelPolicy(string $channel, ToolDefinition $tool, array $policy = []): AuthorizationResult
    {
        // Use provided policy or load from config
        if (empty($policy)) {
            $policy = $this->channelPolicies[$channel] ?? [];
        }

        // Allow all for this channel
        if ($policy['allow_all'] ?? false) {
            return AuthorizationResult::allowed();
        }

        // Check if mutating operations are blocked
        if (($policy['block_mutating'] ?? false) && $tool->mutating) {
            return AuthorizationResult::denied("Mutating operations blocked on channel: {$channel}");
        }

        return AuthorizationResult::allowed();
    }

    /**
     * Check if confirmation is required for this context + tool.
     */
    public function requiresConfirmation(ToolContext $ctx, ToolDefinition $tool): bool
    {
        // Tool explicitly requires confirmation
        if ($tool->requiresConfirmation) {
            return true;
        }

        // Channel policy requires confirmation for mutating operations
        $policy = $this->channelPolicies[$ctx->channel] ?? [];

        if (($policy['require_confirmation_for_mutating'] ?? false) && $tool->mutating) {
            return true;
        }

        return false;
    }

    /**
     * Set custom channel policy.
     *
     * @param array<string, mixed> $policy
     */
    public function setChannelPolicy(string $channel, array $policy): void
    {
        $this->channelPolicies[$channel] = $policy;
    }
}
