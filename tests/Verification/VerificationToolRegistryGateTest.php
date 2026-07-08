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
use Milpa\ToolRuntime\ToolResult;
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ToolRuntime\Verification\VerificationTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins `request_verification` / `resolve_verification`'s behavior when called through
 * {@see ToolRegistry::call()}.
 *
 * Formerly (tool-runtime 0.2) this file pinned a single combined tool that mixed both
 * phases behind one schema ("omit decision to request"). Tool-runtime 0.3 splits it into two
 * tools with clean, non-overlapping schemas — see {@see VerificationTool}. This file's pins
 * carry the SAME behavioral guarantee the 0.2 file established (the registry's generic
 * step-4 confirmation gate never intercepts; a full round trip is exactly two registry
 * calls), now proven across two distinct tool names instead of one overloaded one:
 *
 * - `testRegistryRunsHandleRequestDirectlyOnTheFirstCall` replaces the 0.2 file's
 *   `testRegistryRunsHandleDirectlyOnTheFirstCall` — same assertions, `request_verification`
 *   instead of the old combined tool called with no `decision`.
 * - `testFullRequestResolveRoundTripTakesExactlyTwoRegistryCalls` is adjusted in place: call 1
 *   targets `request_verification`, call 2 targets `resolve_verification` (previously both
 *   calls targeted the same combined tool, distinguished only by the presence of `decision`).
 *
 * `ToolContext::cli()` is used throughout: the `cli` channel policy has no
 * `require_confirmation_for_mutating`, so the only thing that could gate either tool is
 * `ToolDefinition::$requiresConfirmation` itself — and {@see VerificationTool::register()}
 * sets it `false` on both (D2/item-1 of the 0.3 design: no double-gate, ever).
 */
final class VerificationToolRegistryGateTest extends TestCase
{
    private function registryWithVerificationTool(): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        (new VerificationTool(new HumanVerifier()))->register($registry);

        return $registry;
    }

    public function testRegistryRunsHandleRequestDirectlyOnTheFirstCall(): void
    {
        $registry = $this->registryWithVerificationTool();
        $ctx = ToolContext::cli();

        $result = $registry->call(VerificationTool::REQUEST_NAME, [
            'subject' => 'gate:report.publish',
        ], $ctx);

        // handleRequest()'s OWN confirmation (the request phase) — not the registry's
        // generic wrapper.
        $this->assertTrue($result->success);
        $this->assertTrue($result->requiresConfirmation());
        $this->assertArrayHasKey('request_id', $result->data);
        $this->assertEquals('gate:report.publish', $result->data['subject']);

        // No registry-level confirm_token was minted — the generic gate never ran.
        $this->assertArrayNotHasKey('confirm_token', $result->data);
        $this->assertNull($result->getConfirmToken());
    }

    public function testFullRequestResolveRoundTripTakesExactlyTwoRegistryCalls(): void
    {
        $registry = $this->registryWithVerificationTool();
        $ctx = ToolContext::cli();

        // Call 1: request_verification opens the request, request_id is immediately visible.
        $request = $registry->call(VerificationTool::REQUEST_NAME, [
            'subject' => 'gate:report.publish',
        ], $ctx);

        $this->assertTrue($request->requiresConfirmation());
        $requestId = $request->data['request_id'];
        $this->assertIsString($requestId);
        $this->assertNotSame('', $requestId);

        // Call 2: resolve_verification resolves it — no confirm_token involved anywhere.
        $resolved = $registry->call(VerificationTool::RESOLVE_NAME, [
            'decision' => 'grant',
            'principal' => 'agent:reviewer',
            'request_id' => $requestId,
        ], $ctx);

        $this->assertTrue($resolved->success);
        $this->assertFalse($resolved->requiresConfirmation());
        $this->assertEquals('passed', $resolved->data['status']);
        $this->assertEquals('agent:reviewer', $resolved->data['principal']);
    }

    public function testRequestVerificationSchemaHasNoDecisionOrPrincipalFields(): void
    {
        $registry = $this->registryWithVerificationTool();
        $definition = $registry->getDefinition(VerificationTool::REQUEST_NAME);

        $this->assertNotNull($definition);
        $properties = $definition->inputSchema['properties'] ?? [];
        $this->assertArrayNotHasKey('decision', $properties);
        $this->assertArrayNotHasKey('principal', $properties);
        $this->assertEquals(['subject'], $definition->inputSchema['required'] ?? null);
    }

    public function testResolveVerificationRequiresRequestIdDecisionAndPrincipal(): void
    {
        $registry = $this->registryWithVerificationTool();
        $definition = $registry->getDefinition(VerificationTool::RESOLVE_NAME);

        $this->assertNotNull($definition);
        $required = $definition->inputSchema['required'] ?? [];
        $this->assertContains('request_id', $required);
        $this->assertContains('decision', $required);
        $this->assertContains('principal', $required);
        $this->assertNotContains('subject', $required);
    }

    public function testResolveVerificationRejectsMissingDecision(): void
    {
        $registry = $this->registryWithVerificationTool();
        $ctx = ToolContext::cli();

        $result = $registry->call(VerificationTool::RESOLVE_NAME, [
            'request_id' => 'r-1',
            'principal' => 'agent:reviewer',
        ], $ctx);

        $this->assertFalse($result->success);
        $this->assertEquals(ToolResult::VALIDATION_ERROR, $result->meta['code']);
    }

    public function testResolveVerificationFallsBackToRequestIdWhenSubjectOmitted(): void
    {
        $registry = $this->registryWithVerificationTool();
        $ctx = ToolContext::cli();

        $request = $registry->call(VerificationTool::REQUEST_NAME, [
            'subject' => 'gate:report.publish',
        ], $ctx);
        $requestId = $request->data['request_id'];

        // No `subject` echoed back — resolve_verification's schema makes it optional.
        $resolved = $registry->call(VerificationTool::RESOLVE_NAME, [
            'decision' => 'reject',
            'principal' => 'agent:reviewer',
            'request_id' => $requestId,
            'reason' => 'insufficient evidence',
        ], $ctx);

        $this->assertTrue($resolved->success);
        $this->assertEquals('failed', $resolved->data['status']);
        $this->assertStringContainsString((string) $requestId, $resolved->message ?? '');
    }
}
