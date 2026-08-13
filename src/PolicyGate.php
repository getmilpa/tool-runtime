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

use Milpa\Command\Consent\ConsentGrant;
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
        // Reading is free on a local shell: whoever holds one already reads the database and the
        // filesystem, so demanding a card touch to list plugins buys nothing and gets switched off.
        // Mutating is where consent has to be real, and a flag was never that — `--yes` consents to
        // removal in the abstract, so the same yes covers removing any plugin on any host. A
        // signature names the target, so it cannot be presented for a different one.
        'cli' => [
            'allow_all' => false,
            'block_mutating' => false,
            'confirmation_requires_signature' => true,
        ],
        // El TUI es la misma terminal que `cli`: misma máquina, misma persona, mismo riesgo. Hereda
        // su política —leer libre, mutar con firma que nombre la llamada— porque distinguirlos aquí
        // sería decir que el mismo acto pesa distinto según qué pantalla lo lanzó.
        'tui' => [
            'allow_all' => false,
            'block_mutating' => false,
            'confirmation_requires_signature' => true,
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
     * La channel policy efectiva de un canal (lectura pura para la inspección, ADR#13) — delega en
     * el mismo {@see policyFor()} que usa authorize()/requiresConfirmation(), así el plan renderea
     * la MISMA política que el runtime consulta, con el mismo fail-closed para canales desconocidos.
     *
     * @return array<string, mixed>
     */
    public function channelPolicy(string $channel): array
    {
        return $this->policyFor($channel);
    }

    /**
     * ¿Hay un provider de reglas DB conectado? Lectura pura para la inspección (ADR#13).
     */
    public function hasRuleProvider(): bool
    {
        return $this->ruleProvider !== null;
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
        // Note: Use explicit null/empty-string checks instead of empty() to avoid treating "0" as falsy.
        // The principal is typed ?string, so null or "" are the only genuine "no principal" states.
        if (($policy['require_auth'] ?? false) && ($ctx->principal === null || $ctx->principal === '')) {
            return AuthorizationResult::denied(
                "channel '{$ctx->channel}' requires an authenticated principal (require_auth) — none provided."
            );
        }

        // 1b. Where this channel asks for consent, consent means a signature.
        //
        // Scoped to what already needed confirming — not to everything that mutates. Widening it
        // to all mutating calls looked stricter and broke unattended work: a deploy script or a
        // cron job mutates by design and has no hand to touch a card, so the stricter reading
        // would have forced someone to turn the gate off entirely. `requiresConfirmation()` is
        // where this system already decides an act needs a human to say yes; this only changes
        // what saying yes consists of — a flag anyone at the keyboard can pass, or a signature
        // that names the target.
        //
        // The signal is the fingerprint {@see ToolContext::authorizedBy()} records: it can only be
        // there because a signature verified, and no other factory writes it. Checking the channel
        // or the principal string instead would accept anything that spelled itself convincingly.
        if (($policy['confirmation_requires_signature'] ?? false) && $this->requiresConfirmation($ctx, $tool)) {
            $fingerprint = $ctx->extra['signer.fingerprint'] ?? null;

            // A PERMISSION THE SESSION ALREADY RECORDED IS CONSENT TOO.
            //
            // The host asked —«the agent wants to run X, do you authorise it in this session?»—, a
            // human answered yes, and the grant was written to the session with its acta. This gate
            // did not look there, so the app asked a question whose answer could not work: measured
            // end to end in greenhouse evidence/0176, where the config was never written after an
            // explicit yes. The house was not being strict; it was asking something it could not
            // honour.
            //
            // IT IS WEAKER THAN A SIGNATURE AND THAT IS SAID, NOT HIDDEN: a signature names the
            // call's arguments and this names only the tool, inside one session. It is the strong
            // form that remains for everything with no session behind it.
            // EL HECHO, NO SU ORTOGRAFÍA. Antes esto era una lista de cadenas y se comparaba
            // `in_array($tool->name, …)` — o sea, se comparaba UI. Un permiso escrito `config:set`
            // no encajaba con una herramienta llamada `config_set`, y el sí del humano no valía
            // (greenhouse evidence/0176).
            //
            // Ahora decide sobre un `ConsentGrant`: un principal respondió una pregunta concreta,
            // para un acto concreto, bajo un contexto concreto. La identidad la compara el grant por
            // `OperationId`, y esta compuerta no aprende a deletrear (greenhouse decisions/0030).
            //
            // La lista de cadenas se sigue aceptando y está DEPRECADA: quitarla de golpe rompería a
            // quien ya la manda, y dejarla sin decirlo la volvería el contrato de facto.
            // UNA SESIÓN PUEDE TENER MÁS DE UN SÍ. Aceptar un solo grant obligaba al host a pisar
            // el contexto en cada permiso y sólo el último sobrevivía: con dos autorizaciones, una
            // se perdía en silencio. Se acepta la lista, y `consent.grant` singular se sigue
            // aceptando porque ya se publicó.
            $argumentos = $ctx->extra['consent.arguments'] ?? [];
            $grants = $ctx->extra['consent.grants'] ?? [];
            if (($uno = $ctx->extra['consent.grant'] ?? null) instanceof ConsentGrant) {
                $grants[] = $uno;
            }

            $porLaSesion = false;
            foreach (\is_array($grants) ? $grants : [] as $g) {
                if ($g instanceof ConsentGrant && $g->covers($tool->name, $argumentos)) {
                    $porLaSesion = true;

                    break;
                }
            }

            if (! $porLaSesion) {
                /** @deprecated desde v0.10.0 — manda un ConsentGrant */
                $concedidas = $ctx->extra['session.granted'] ?? null;
                $porLaSesion = \is_array($concedidas) && \in_array($tool->name, $concedidas, true);
            }

            if (! $porLaSesion && (!\is_string($fingerprint) || $fingerprint === '')) {
                return AuthorizationResult::denied(
                    "'{$tool->name}' needs explicit consent and channel '{$ctx->channel}' takes consent as a signature naming this call — none was presented."
                );
            }
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
        // A verified signature already IS the consent, and a stronger one: the token flow asks
        // "are you sure" of whoever holds the terminal, while the signature was produced by
        // whoever holds the key and names this exact call. Asking again afterwards is the double
        // gate this design forbids — and worse than redundant, since the second question is the
        // weaker of the two and would be the one a tired operator learns to click through.
        $fingerprint = $ctx->extra['signer.fingerprint'] ?? null;
        if (\is_string($fingerprint) && $fingerprint !== '') {
            return false;
        }

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
