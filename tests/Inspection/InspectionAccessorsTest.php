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

use Milpa\Eventing\EventDispatcher;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Read-only inspection accessors for InvocationPlan — exposing wiring state without changing behavior.
 *
 * ADR#13: The InvocationPlanBuilder needs to inspect whether the rate-limiter, dispatcher, and
 * rule-provider are wired, so it can mark pipeline steps as Skipped (no wiring) vs Active.
 * These accessors are pure reads — zero behavior change.
 */
class InspectionAccessorsTest extends TestCase
{
    public function test_registry_reports_rate_limiter_and_dispatcher_wiring(): void
    {
        $bare = new ToolRegistry(new NullLogger());
        self::assertFalse($bare->hasRateLimiter());
        self::assertFalse($bare->hasDispatcher());

        $wired = new ToolRegistry(new NullLogger(), new EventDispatcher(new NullLogger()));
        self::assertTrue($wired->hasDispatcher());
    }

    public function test_policy_gate_exposes_channel_policy_read_only(): void
    {
        $gate = new PolicyGate();
        self::assertSame(['allow_all' => false, 'require_auth' => true], $gate->channelPolicy('web'));
        self::assertSame(['allow_all' => true], $gate->channelPolicy('cli'));
        // Canal desconocido → fail-closed (UNKNOWN_CHANNEL_POLICY)
        self::assertSame(['require_auth' => true], $gate->channelPolicy('inventado'));
    }

    public function test_policy_gate_reports_rule_provider_wiring(): void
    {
        $gate = new PolicyGate();
        self::assertFalse($gate->hasRuleProvider());
    }
}
