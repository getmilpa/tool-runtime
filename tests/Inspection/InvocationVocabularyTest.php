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

use Milpa\ToolRuntime\Inspection\InvocationPlan;
use Milpa\ToolRuntime\Inspection\InvocationStep;
use Milpa\ToolRuntime\Inspection\InvocationStepKind;
use Milpa\ToolRuntime\Inspection\InvocationStepRole;
use Milpa\ToolRuntime\Inspection\StepPresence;
use PHPUnit\Framework\TestCase;

final class InvocationVocabularyTest extends TestCase
{
    public function test_inspection_order_lists_the_eleven_kinds_in_the_model_order(): void
    {
        $order = InvocationStepKind::inspectionOrder();
        $values = array_map(static fn (InvocationStepKind $k): string => $k->value, $order);

        self::assertSame([
            'resolve', 'validate', 'clamp', 'authorize', 'rate_limit',
            'plan_mode', 'confirm', 'emit_executing', 'execute', 'contain_exception', 'audit',
        ], $values);
        self::assertCount(11, $order);
        self::assertSame(InvocationStepKind::cases(), $order, 'inspectionOrder() debe cubrir TODOS los casos, sin faltar ni sobrar');
    }

    public function test_step_presence_has_the_four_frozen_semantics_and_no_inert(): void
    {
        $values = array_map(static fn (StepPresence $p): string => $p->value, StepPresence::cases());
        self::assertSame(['active', 'conditional', 'dormant', 'skipped'], $values);
        self::assertNull(StepPresence::tryFrom('inert'), 'Inert fue reemplazado por Dormant (Enmienda 3)');
    }

    public function test_step_role_covers_the_seven_shapes(): void
    {
        $values = array_map(static fn (InvocationStepRole $r): string => $r->value, InvocationStepRole::cases());
        self::assertSame(['guard', 'transform', 'branch', 'hook', 'execution', 'boundary', 'outcome'], $values);
    }

    public function test_invocation_step_serializes_wraps_and_flags(): void
    {
        $step = new InvocationStep(
            InvocationStepKind::ContainException,
            InvocationStepRole::Boundary,
            StepPresence::Active,
            blocking: false,
            mutates: false,
            wraps: InvocationStepKind::Execute,
            source: 'envuelve execute; mapea \\Throwable → INTERNAL_ERROR',
        );

        self::assertSame([
            'kind' => 'contain_exception',
            'role' => 'boundary',
            'presence' => 'active',
            'blocking' => false,
            'mutates' => false,
            'wraps' => 'execute',
            'source' => 'envuelve execute; mapea \\Throwable → INTERNAL_ERROR',
        ], $step->toArray());
    }

    public function test_invocation_plan_serializes_the_full_frozen_shape(): void
    {
        $plan = new InvocationPlan(
            schemaVersion: '1.0',
            operation: 'settings_update',
            channel: 'web',
            context: ['actor' => null, 'scopes' => [], 'mode' => 'execute'],
            assumptions: ['actor no suministrado → anónimo'],
            wiring: ['rateLimiter' => 'absent', 'dispatcher' => 'absent', 'ruleProvider' => 'absent'],
            steps: [],
        );

        $out = $plan->toArray();
        self::assertSame('1.0', $out['schemaVersion']);
        self::assertSame('web', $out['channel']);
        self::assertSame(['actor' => null, 'scopes' => [], 'mode' => 'execute'], $out['context']);
        self::assertSame(['actor no suministrado → anónimo'], $out['assumptions']);
        self::assertSame(['rateLimiter' => 'absent', 'dispatcher' => 'absent', 'ruleProvider' => 'absent'], $out['wiring']);
        self::assertSame([], $out['steps']);
    }
}
