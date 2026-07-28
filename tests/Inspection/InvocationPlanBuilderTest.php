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

namespace Milpa\ToolRuntime\Tests\Inspection;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Inspection\InvocationPlanBuilder;
use Milpa\ToolRuntime\Inspection\InvocationStepKind;
use Milpa\ToolRuntime\Inspection\InvocationStepRole;
use Milpa\ToolRuntime\Inspection\RegistryWiring;
use Milpa\ToolRuntime\Inspection\StepPresence;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * THE ACCEPTANCE TEST (ADR#13 P13.1): ancla que {@see InvocationPlanBuilder::build()} produce un
 * plan HONESTO — cada paso describe lo que el runtime REALMENTE hace, incluyendo los pasos que
 * nunca disparan (Dormant/Skipped), no una lista optimista de "todo corre".
 */
final class InvocationPlanBuilderTest extends TestCase
{
    private function builder(): InvocationPlanBuilder
    {
        return new InvocationPlanBuilder(new PolicyGate());
    }

    /** Una ToolDefinition equivalente a settings_update (escaneada → mutating=false, sin confirm/scopes/clamps). */
    private function settingsLikeTool(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'settings_update',
            description: 'Actualiza configuración del sitio.',
            inputSchema: ['type' => 'object', 'properties' => ['siteName' => ['type' => 'string']]],
            callback: static fn (array $args): array => $args,
            scopes: [],
            mutating: false,
            requiresConfirmation: false,
            timeout: 30,
            clamps: [],
        );
    }

    /**
     * Un tool mutante que NO pide confirmación por sí mismo (mutating=true, requiresConfirmation=false)
     * — como VerificationTool. En telegram (require_confirmation_for_mutating=true), la segunda rama
     * de PolicyGate::requiresConfirmation() dispara IGUAL, aunque el tool nunca lo pidió.
     */
    private function mutatingNoSelfConfirmTool(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'verification_tool',
            description: 'Muta estado sin pedir confirmación propia.',
            inputSchema: [],
            callback: static fn (array $args): array => $args,
            scopes: [],
            mutating: true,
            requiresConfirmation: false,
            timeout: 30,
            clamps: [],
        );
    }

    public function test_web_plan_with_actor_marks_the_mutating_gates_dormant(): void
    {
        $wiring = new RegistryWiring(hasRateLimiter: false, hasDispatcher: false, hasRuleProvider: false);
        $plan = $this->builder()->build($this->settingsLikeTool(), ToolContext::web('u', []), $wiring);

        $byKind = [];
        foreach ($plan->steps as $s) {
            $byKind[$s->kind->value] = $s;
        }

        // Authorize: Active + blocking (web require_auth:true; el actor 'u' sin scopes no basta si hubiera scopes;
        //   aquí sin scopes declarados igual authorize corre — Active)
        self::assertSame(StepPresence::Active, $byKind['authorize']->presence);
        self::assertStringContainsString('web', $byKind['authorize']->source);

        // RateLimit: Skipped (no wired)
        self::assertSame(StepPresence::Skipped, $byKind['rate_limit']->presence);

        // EmitExecuting: Skipped (no dispatcher)
        self::assertSame(StepPresence::Skipped, $byKind['emit_executing']->presence);

        // Confirm: Dormant (settings_update no setea confirm; mutating=false)
        self::assertSame(StepPresence::Dormant, $byKind['confirm']->presence);

        // PlanMode: Conditional, off (ctx.mode execute)
        self::assertSame(StepPresence::Conditional, $byKind['plan_mode']->presence);

        // ContainException: Boundary, wraps=Execute
        self::assertSame(InvocationStepRole::Boundary, $byKind['contain_exception']->role);
        self::assertSame(InvocationStepKind::Execute, $byKind['contain_exception']->wraps);

        // Audit: Outcome, cobertura real en source (menciona lo que NO audita)
        self::assertSame(InvocationStepRole::Outcome, $byKind['audit']->role);
        self::assertStringContainsString('resolve', strtolower($byKind['audit']->source)); // enumera resolve-miss como NO auditado
    }

    public function test_plan_carries_schema_version_context_and_wiring(): void
    {
        $plan = $this->builder()->build(
            $this->settingsLikeTool(),
            ToolContext::web('u', []),
            new RegistryWiring(false, false, false)
        );

        self::assertSame('1.0', $plan->schemaVersion);
        self::assertSame('web', $plan->channel);
        self::assertSame('absent', $plan->wiring['rateLimiter']);
        self::assertCount(11, $plan->steps);
    }

    public function test_cli_channel_shows_authorize_never_blocks_godmode(): void
    {
        $plan = $this->builder()->build(
            $this->settingsLikeTool(),
            ToolContext::cli(),
            new RegistryWiring(false, false, false)
        );
        $authorize = null;
        foreach ($plan->steps as $s) {
            if ($s->kind === InvocationStepKind::Authorize) {
                $authorize = $s;
            }
        }
        self::assertStringContainsString('allow_all', $authorize->source); // cli allow_all:true (god-mode) — honesto
    }

    /**
     * CRITICAL regression: PolicyGate::requiresConfirmation() (:228-243) tiene DOS ramas que
     * devuelven true — la segunda es "channel require_confirmation_for_mutating && tool.mutating",
     * sin importar que el tool NUNCA haya pedido confirmación por sí mismo. Un plan que pinte esto
     * como Dormant miente en la dirección peligrosa: dice "no hay confirmación" cuando el gate real
     * SÍ dispara.
     */
    public function test_telegram_channel_marks_confirm_conditional_when_channel_policy_requires_it_for_mutating_tools(): void
    {
        $plan = $this->builder()->build(
            $this->mutatingNoSelfConfirmTool(),
            ToolContext::telegram('chat-1'),
            new RegistryWiring(false, false, false),
        );

        $confirm = null;
        foreach ($plan->steps as $s) {
            if ($s->kind === InvocationStepKind::Confirm) {
                $confirm = $s;
            }
        }

        self::assertSame(StepPresence::Conditional, $confirm->presence);
        self::assertSame(InvocationStepRole::Branch, $confirm->role);
        self::assertTrue($confirm->blocking);
        self::assertStringContainsString('require_confirmation_for_mutating', $confirm->source);
    }

    /**
     * IMPORTANT: la Enmienda 4 (assumptions) solo se ejercita con un actor GENUINAMENTE anónimo
     * (principal vacío/null) — `ToolContext::web('u', [])` NO califica, tiene principal='u'. Aquí se
     * construye el contexto directamente (sin la factory `web()`, que exige un principal no vacío)
     * para dejar `principal: null` real, sin fabricar un actor.
     */
    public function test_web_plan_with_no_principal_records_the_anonymous_assumption(): void
    {
        $anonymousWeb = new ToolContext(principal: null, channel: 'web', scopes: []);

        $plan = $this->builder()->build(
            $this->settingsLikeTool(),
            $anonymousWeb,
            new RegistryWiring(false, false, false),
        );

        $found = false;
        foreach ($plan->assumptions as $assumption) {
            if (str_contains($assumption, 'actor anónimo') && str_contains($assumption, 'authorize denegaría')) {
                $found = true;
            }
        }
        self::assertTrue($found, 'Se esperaba una assumption anotando actor anónimo + canal que exige auth');
    }
}
