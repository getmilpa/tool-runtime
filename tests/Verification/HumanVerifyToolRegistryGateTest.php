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

namespace Milpa\ToolRuntime\Tests\Verification;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\HumanVerifyTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins `human_verify`'s behavior when called through {@see ToolRegistry::call()}.
 *
 * `ToolContext::cli()` is used throughout: the `cli` channel policy has no
 * `require_confirmation_for_mutating`, so the only thing that can gate `human_verify` is
 * `ToolDefinition::$requiresConfirmation` itself (set from {@see HumanVerifyTool::register()}'s
 * `ToolOptions`).
 */
final class HumanVerifyToolRegistryGateTest extends TestCase
{
    private function registryWithHumanVerify(): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        (new HumanVerifyTool(new HumanVerifier()))->register($registry);

        return $registry;
    }

    /**
     * NEW behavior (tool-runtime 0.2): `HumanVerifyTool::register()` now registers with
     * `requiresConfirmation: false` (`handle()` owns its own two-phase `request_id` protocol),
     * so the registry's generic confirm-token gate (step 4 of {@see ToolRegistry::call()}) no
     * longer intercepts. `handle()` runs directly on the very first call — its own request-phase
     * confirmation (carrying `request_id`) comes back in ONE call, not two.
     *
     * Formerly (tool-runtime 0.1, `requiresConfirmation: true`) this same call would have hit
     * the registry's generic wrapper first: a `confirm_token` with no `request_id` anywhere,
     * requiring a second call just to redeem it before `handle()` ran at all. See
     * {@see testFullRequestResolveRoundTripTakesExactlyTwoRegistryCalls()} for the full
     * before/after contrast.
     */
    public function testRegistryRunsHandleDirectlyOnTheFirstCall(): void
    {
        $registry = $this->registryWithHumanVerify();
        $ctx = ToolContext::cli();

        $result = $registry->call(HumanVerifier::NAME, [
            'subject' => 'gate:report.publish',
        ], $ctx);

        // handle()'s OWN confirmation (its request phase) — not the registry's generic wrapper.
        $this->assertTrue($result->success);
        $this->assertTrue($result->requiresConfirmation());
        $this->assertArrayHasKey('request_id', $result->data);
        $this->assertEquals('gate:report.publish', $result->data['subject']);

        // No registry-level confirm_token was minted — the generic gate never ran.
        $this->assertArrayNotHasKey('confirm_token', $result->data);
        $this->assertNull($result->getConfirmToken());
    }

    /**
     * Second proof the registry gate no longer intercepts `human_verify`: a full
     * request -> resolve round trip now completes in exactly the two `ToolRegistry::call()`
     * invocations `HumanVerifyTool` was designed around — no confirm-token dance stacked in
     * between, and no third call needed just to reach a verdict.
     */
    public function testFullRequestResolveRoundTripTakesExactlyTwoRegistryCalls(): void
    {
        $registry = $this->registryWithHumanVerify();
        $ctx = ToolContext::cli();

        // Call 1: opens the request, request_id is immediately visible.
        $request = $registry->call(HumanVerifier::NAME, [
            'subject' => 'gate:report.publish',
        ], $ctx);

        $this->assertTrue($request->requiresConfirmation());
        $requestId = $request->data['request_id'];
        $this->assertIsString($requestId);
        $this->assertNotSame('', $requestId);

        // Call 2: resolves it — no confirm_token involved anywhere in this round trip.
        $resolved = $registry->call(HumanVerifier::NAME, [
            'subject' => 'gate:report.publish',
            'decision' => 'grant',
            'principal' => 'agent:reviewer',
            'request_id' => $requestId,
        ], $ctx);

        $this->assertTrue($resolved->success);
        $this->assertFalse($resolved->requiresConfirmation());
        $this->assertEquals('passed', $resolved->data['status']);
        $this->assertEquals('agent:reviewer', $resolved->data['principal']);
    }
}
