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

namespace Milpa\ToolRuntime;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Contracts\PolicyRuleProviderInterface;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Policy gate for tool authorization.
 *
 * Checks scopes, channel policies, and tool permissions.
 * Supports dynamic rules via PolicyRuleProviderInterface (e.g. the host's Doctrine-backed provider).
 *
 * **Fail-closed on unknown channels.** A channel nobody registered no longer inherits the laxest
 * possible policy (the old `?? []`, under which `require_auth` defaulted to false and an anonymous
 * caller sailed through). An unregistered channel now falls back to {@see self::UNKNOWN_CHANNEL_POLICY}
 * — `require_auth: true`, no `allow_all` — and the gate emits a learnable warning naming it. An
 * unknown channel is treated as untrusted, never as the most permissive one.
 */
class PolicyGate
{
    /**
     * The fail-closed policy for a channel that was never registered via {@see setChannelPolicy()}.
     * It requires an authenticated principal and grants no blanket access — the deliberate opposite
     * of the old fail-open `?? []`. Register the channel explicitly to relax it.
     *
     * @var array<string, mixed>
     */
    private const UNKNOWN_CHANNEL_POLICY = ['require_auth' => true];

    /**
     * Optional provider for database-driven policy rules.
     */
    private ?PolicyRuleProviderInterface $ruleProvider = null;

    /**
     * @param LoggerInterface $logger sink for the learnable warning emitted when an unregistered
     *                                channel falls back to the fail-closed policy — defaults to a
     *                                {@see NullLogger} so existing `new PolicyGate()` callers keep
     *                                working unchanged
     */
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

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
        if (!isset($this->channelPolicies[$ctx->channel])) {
            $this->warnUnknownChannel($ctx->channel);
        }
        $policy = $this->policyFor($ctx->channel);

        // 1. Check require_auth first - if channel requires auth, principal must be set
        if (($policy['require_auth'] ?? false) && empty($ctx->principal)) {
            return AuthorizationResult::denied(
                "channel '{$ctx->channel}' requires an authenticated principal (require_auth) — none provided."
            );
        }

        // 2. Check if tool requires specific scopes
        if (!empty($tool->scopes)) {
            if (!$ctx->hasAnyScope($tool->scopes)) {
                return AuthorizationResult::denied(
                    "Missing required scope for tool '{$tool->name}'. Need one of: " . implode(', ', $tool->scopes)
                        . ' — context has: ' . (empty($ctx->scopes) ? '(none)' : implode(', ', $ctx->scopes)) . '.'
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
                    "Policy rule #{$rule->getId()} for tool '{$tool->name}' requires one of these scopes: "
                        . implode(', ', $requiredScopes)
                        . ' — context has: ' . (empty($ctx->scopes) ? '(none)' : implode(', ', $ctx->scopes)) . '.'
                );
            }
        }

        if ($rule->getEffect() === 'allow') {
            return AuthorizationResult::allowed();
        }

        return AuthorizationResult::denied(
            $rule->getDescription() ?? (
                "Denied by policy rule #{$rule->getId()} for tool '{$tool->name}' on channel '{$ctx->channel}' "
                . '(principal: ' . ($ctx->principal ?? '(none)') . ').'
            )
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
        // Use provided policy or load from config (fail-closed for an unregistered channel).
        if (empty($policy)) {
            $policy = $this->policyFor($channel);
        }

        // Allow all for this channel
        if ($policy['allow_all'] ?? false) {
            return AuthorizationResult::allowed();
        }

        // Check if mutating operations are blocked
        if (($policy['block_mutating'] ?? false) && $tool->mutating) {
            return AuthorizationResult::denied(
                "Mutating tool '{$tool->name}' is blocked on channel '{$channel}' (block_mutating policy)."
            );
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
        $policy = $this->policyFor($ctx->channel);

        if (($policy['require_confirmation_for_mutating'] ?? false) && $tool->mutating) {
            return true;
        }

        return false;
    }

    /**
     * The policy for `$channel`: its registered rules, or {@see self::UNKNOWN_CHANNEL_POLICY} — the
     * fail-closed default — when the channel was never registered. Unlike the old `?? []`, an unknown
     * channel gets `require_auth: true` (and no `allow_all`), so it is treated as untrusted rather
     * than as the laxest possible policy. This lookup is silent; {@see authorize()} is where the
     * one-per-call learnable warning is emitted, so composing it here does not double-warn.
     *
     * @return array<string, mixed>
     */
    private function policyFor(string $channel): array
    {
        return $this->channelPolicies[$channel] ?? self::UNKNOWN_CHANNEL_POLICY;
    }

    /**
     * Emits the learnable warning for a channel that reached the gate without being registered — it
     * names the channel, states that it fell back to the FAIL-CLOSED policy, and points at the fix
     * (register it) and the concept it enforces. Routed through the injected logger (a
     * {@see NullLogger} by default), so it is observable in production and silent in unit tests that
     * do not wire a logger.
     */
    private function warnUnknownChannel(string $channel): void
    {
        $this->logger->warning(\sprintf(
            "PolicyGate: channel '%s' is not a registered channel — falling back to a FAIL-CLOSED "
            . 'policy (require_auth, no allow_all). An unregistered channel is treated as untrusted, '
            . 'never as the laxest policy. Register it explicitly via setChannelPolicy() to define its '
            . 'rules. Why Milpa fails closed on unknown channels: '
            . 'https://academy.milpa.lat/learn/fundamentos/politicas-explicitas',
            $channel,
        ));
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
