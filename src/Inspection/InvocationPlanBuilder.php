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

namespace Milpa\ToolRuntime\Inspection;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;

/**
 * Construye un {@see InvocationPlan} HONESTO para un tool+canal+wiring dado (ADR#13 P13.1).
 *
 * "Honesto" significa: cada paso de {@see InvocationStepKind::inspectionOrder()} describe lo que
 * {@see \Milpa\ToolRuntime\ToolRegistry::call()} REALMENTE haría con estos datos estáticos —
 * incluyendo los pasos que nunca disparan (Dormant), los que no aplican por falta de wiring
 * (Skipped), y los que solo pueden decidirse en runtime (Conditional). Este builder SOLO lee
 * estado — nunca invoca `call()`, nunca muta nada. `call()` permanece byte-idéntico.
 */
final class InvocationPlanBuilder
{
    /** Cobertura REAL de {@see \Milpa\ToolRuntime\Events\ToolAuditLogger} vía `tool.executed`/`tool.failed`. */
    private const AUDIT_SOURCE = 'audita: validate-fail, authz-fail, rate-limit, cache-hit, execute-éxito, '
        . 'execute-fallo; NO audita: resolve-miss, plan-mode, confirm, veto';

    public function __construct(private readonly PolicyGate $policyGate)
    {
    }

    /**
     * Walks every station in inspection order and asks what it would do with this call.
     *
     * Nothing is executed and nothing is dispatched: the answer comes from the channel policy, the
     * tool's own declaration, and what the host actually wired. That is the point — the plan has to
     * be safe to ask for on a mutating operation, or it would only be usable where it is not
     * needed.
     */
    public function build(ToolDefinition $tool, ToolContext $ctx, RegistryWiring $wiring): InvocationPlan
    {
        $policy = $this->policyGate->channelPolicy($ctx->channel);

        $steps = [];
        foreach (InvocationStepKind::inspectionOrder() as $kind) {
            $steps[] = $this->resolveStep($kind, $tool, $ctx, $wiring, $policy);
        }

        return new InvocationPlan(
            schemaVersion: '1.0',
            operation: $tool->name,
            channel: $ctx->channel,
            context: [
                'actor' => $ctx->principal,
                'scopes' => $ctx->scopes,
                'mode' => $ctx->mode,
            ],
            assumptions: $this->buildAssumptions($ctx, $policy),
            wiring: [
                'rateLimiter' => $wiring->hasRateLimiter ? 'present' : 'absent',
                'dispatcher' => $wiring->hasDispatcher ? 'present' : 'absent',
                'ruleProvider' => $wiring->hasRuleProvider ? 'present' : 'absent',
            ],
            steps: $steps,
        );
    }

    /**
     * @param array<string, mixed> $policy la channel policy efectiva de `$ctx->channel`
     */
    private function resolveStep(
        InvocationStepKind $kind,
        ToolDefinition $tool,
        ToolContext $ctx,
        RegistryWiring $wiring,
        array $policy,
    ): InvocationStep {
        return match ($kind) {
            InvocationStepKind::Resolve => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Guard,
                presence: StepPresence::Active,
                blocking: true,
                mutates: false,
                wraps: null,
                source: 'registry lookup',
            ),
            InvocationStepKind::Validate => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Guard,
                presence: !empty($tool->inputSchema) ? StepPresence::Active : StepPresence::Skipped,
                blocking: true,
                mutates: false,
                wraps: null,
                source: 'tool inputSchema',
            ),
            InvocationStepKind::Clamp => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Transform,
                presence: !empty($tool->clamps) ? StepPresence::Active : StepPresence::Skipped,
                blocking: false,
                mutates: !empty($tool->clamps),
                wraps: null,
                source: 'tool clamps',
            ),
            InvocationStepKind::Authorize => $this->resolveAuthorize($tool, $ctx, $policy),
            InvocationStepKind::RateLimit => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Guard,
                presence: $wiring->hasRateLimiter ? StepPresence::Active : StepPresence::Skipped,
                blocking: true,
                mutates: false,
                wraps: null,
                source: \sprintf(
                    'host wiring: rateLimiter %s; cost mutating?5:1 (mutating=%s → cost %d)',
                    $wiring->hasRateLimiter ? 'present' : 'absent',
                    $tool->mutating ? 'true' : 'false',
                    $tool->mutating ? 5 : 1,
                ),
            ),
            InvocationStepKind::PlanMode => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Branch,
                presence: StepPresence::Conditional,
                blocking: false,
                mutates: false,
                wraps: null,
                source: "ctx.mode == 'plan'; actual: {$ctx->mode}",
            ),
            InvocationStepKind::Confirm => $this->resolveConfirm($tool, $policy),
            InvocationStepKind::EmitExecuting => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Hook,
                presence: $wiring->hasDispatcher ? StepPresence::Active : StepPresence::Skipped,
                blocking: true,
                mutates: false,
                wraps: null,
                source: \sprintf(
                    'host wiring: dispatcher %s (anchor + cache/veto)',
                    $wiring->hasDispatcher ? 'present' : 'absent',
                ),
            ),
            InvocationStepKind::Execute => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Execution,
                presence: StepPresence::Active,
                blocking: true,
                mutates: $tool->mutating,
                wraps: null,
                source: 'callback; _ctx inyectado',
            ),
            InvocationStepKind::ContainException => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Boundary,
                presence: StepPresence::Active,
                blocking: false,
                mutates: false,
                wraps: InvocationStepKind::Execute,
                source: 'envuelve execute; \\Throwable → INTERNAL_ERROR',
            ),
            InvocationStepKind::Audit => new InvocationStep(
                kind: $kind,
                role: InvocationStepRole::Outcome,
                presence: StepPresence::Active,
                blocking: false,
                mutates: false,
                wraps: null,
                source: self::AUDIT_SOURCE,
            ),
        };
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function resolveAuthorize(ToolDefinition $tool, ToolContext $ctx, array $policy): InvocationStep
    {
        $requiresAuth = (bool) ($policy['require_auth'] ?? false);
        $allowAll = (bool) ($policy['allow_all'] ?? false);

        $parts = [\sprintf(
            "canal '%s': require_auth=%s, allow_all=%s",
            $ctx->channel,
            $requiresAuth ? 'true' : 'false',
            $allowAll ? 'true' : 'false',
        )];

        if ($allowAll) {
            $parts[] = 'allow_all: god-mode — nunca bloquea';
        }

        if (!empty($tool->scopes)) {
            $parts[] = 'tool exige scopes: ' . implode(', ', $tool->scopes);
        } else {
            $parts[] = 'tool sin scopes declarados';
        }

        $parts[] = $this->policyGate->hasRuleProvider()
            ? 'DB rules: activas'
            : 'DB rules: skipped (no provider)';

        return new InvocationStep(
            kind: InvocationStepKind::Authorize,
            role: InvocationStepRole::Guard,
            presence: StepPresence::Active,
            blocking: true,
            mutates: false,
            wraps: null,
            source: implode('; ', $parts),
        );
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function resolveConfirm(ToolDefinition $tool, array $policy): InvocationStep
    {
        // Espeja EXACTAMENTE las dos ramas de PolicyGate::requiresConfirmation() (:228-243) — en
        // el mismo orden. Ambas ramas hacen que requiresConfirmation() devuelva true y disparen el
        // mismo two-call token dance, así que ambas son Conditional/Branch/blocking idénticas.
        if ($tool->requiresConfirmation) {
            return new InvocationStep(
                kind: InvocationStepKind::Confirm,
                role: InvocationStepRole::Branch,
                presence: StepPresence::Conditional,
                blocking: true,
                mutates: false,
                wraps: null,
                source: 'tool.requiresConfirmation → el confirm-gate dispara (el primer call pide token)',
            );
        }

        $requireConfirmationForMutating = (bool) ($policy['require_confirmation_for_mutating'] ?? false);

        if ($requireConfirmationForMutating && $tool->mutating) {
            return new InvocationStep(
                kind: InvocationStepKind::Confirm,
                role: InvocationStepRole::Branch,
                presence: StepPresence::Conditional,
                blocking: true,
                mutates: false,
                wraps: null,
                source: 'channel policy require_confirmation_for_mutating + tool.mutating → el confirm-gate '
                    . 'dispara (aunque el tool no lo pida)',
            );
        }

        if ($requireConfirmationForMutating) {
            // A este punto mutating ya es false por eliminación (la rama true/true ya retornó arriba).
            return new InvocationStep(
                kind: InvocationStepKind::Confirm,
                role: InvocationStepRole::Branch,
                presence: StepPresence::Dormant,
                blocking: false,
                mutates: false,
                wraps: null,
                source: 'la regla existe (channel require_confirmation_for_mutating) pero mutating=false '
                    . 'la hace imposible',
            );
        }

        return new InvocationStep(
            kind: InvocationStepKind::Confirm,
            role: InvocationStepRole::Branch,
            presence: StepPresence::Dormant,
            blocking: false,
            mutates: false,
            wraps: null,
            source: 'ni tool.requiresConfirmation ni channel policy require_confirmation_for_mutating aplican',
        );
    }

    /**
     * @param array<string, mixed> $policy
     *
     * @return list<string>
     */
    private function buildAssumptions(ToolContext $ctx, array $policy): array
    {
        $assumptions = [];

        // NUNCA `empty($ctx->principal)`: un principal real como '0' es un id válido y
        // `empty('0') === true` en PHP — borraría un actor explícito y lo pintaría como
        // anónimo, exactamente lo que la Enmienda 4 prohíbe.
        if (($ctx->principal === null || $ctx->principal === '') && ($policy['require_auth'] ?? false)) {
            $assumptions[] = "actor anónimo; {$ctx->channel} exige auth → authorize denegaría";
        }

        return $assumptions;
    }
}
