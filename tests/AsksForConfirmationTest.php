<?php

/**
 * This file is part of Milpa Tool Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\TestCase;

/**
 * Telling a call that ASKED from a call that DID, from the payload that travelled.
 *
 * A confirmation request and a completed write both come back successful, and a session log that
 * cannot separate them counts two mutations where there was one — which is the count that governs
 * consent. The predicate exists as an instance method already; this is the same rule reachable from
 * the serialized shape, because whoever needs it downstream holds a string rather than an object.
 *
 * One rule, called. A second reader of `requires_confirmation` somewhere else disagrees with this
 * one the day either changes.
 */
final class AsksForConfirmationTest extends TestCase
{
    /** The shape that reaches the model and the session log: the data payload, flat. */
    public function testTheEnvelopeThatTravelsIsRecognised(): void
    {
        $viajó = (string) json_encode([
            'requires_confirmation' => true,
            'confirm_token' => 'fc6c5582e09d86c7483438eee53f2485',
            'action_summary' => 'config_set(key=agent.treeBudget, value=7)',
            'expires_at' => '2026-08-15T14:08:11+00:00',
        ]);

        self::assertTrue(ToolResult::asksForConfirmation($viajó));
    }

    /** And the full serialization, where the flag lives under meta. */
    public function testTheFullSerializationIsRecognisedToo(): void
    {
        $completo = ToolResult::confirmation('¿lo autorizas?', ['confirm_token' => 'abc'], 'config_set', 'config', 'agent.treeBudget')->toArray();

        self::assertTrue(ToolResult::asksForConfirmation($completo));
    }

    /** The call that actually wrote is not asking for anything. */
    public function testTheCallThatDidIsNotAsking(): void
    {
        $hecho = (string) json_encode(['ok' => true, 'key' => 'agent.treeBudget', 'written_to' => '.milpa/agent.json']);

        self::assertFalse(ToolResult::asksForConfirmation($hecho));
    }

    /**
     * THE EDGE THAT WOULD MAKE IT LIE.
     *
     * A plan-mode result carries `requires_confirmation` too, nested inside its plan: it says what
     * WOULD need confirming, it is not itself asking. Reading the flag wherever it appears would
     * record a plan as a pending mutation, which is the opposite of what a plan is.
     */
    public function testAPlanSaysWhatWOULDNeedConfirmingAndIsNotAsking(): void
    {
        $plan = (string) json_encode([
            'plan' => [
                'tool' => 'config_set',
                'mutating' => true,
                'requires_confirmation' => true,
                'args' => ['key' => 'agent.treeBudget'],
            ],
        ]);

        self::assertFalse(ToolResult::asksForConfirmation($plan));
    }

    public function testNonsenseIsNotAConfirmationRequest(): void
    {
        self::assertFalse(ToolResult::asksForConfirmation('ok: dos plugins'));
        self::assertFalse(ToolResult::asksForConfirmation(''));
        self::assertFalse(ToolResult::asksForConfirmation(null));
    }

    /** The instance predicate and the static one are the same rule, not two that agree today. */
    public function testTheInstanceAsksTheSameRule(): void
    {
        $pide = ToolResult::confirmation('¿lo autorizas?', ['confirm_token' => 'abc'], 'config_set', 'config', 'agent.treeBudget');

        self::assertTrue($pide->requiresConfirmation());
        self::assertSame($pide->requiresConfirmation(), ToolResult::asksForConfirmation($pide->toArray()));
    }
}
