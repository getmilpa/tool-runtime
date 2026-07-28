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

namespace Milpa\ToolRuntime\Tests\Identity;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate that makes `require_signature_for_mutating` mean something.
 *
 * A policy key nothing reads is the failure this whole slice was about: it reviews well, it renders
 * in the plan, and it stops nothing. So the falsifier comes first — a mutating call on a bare `cli`
 * context must be refused, and if it is not, the key is decoration.
 */
#[CoversClass(PolicyGate::class)]
final class SignedMutationPolicyTest extends TestCase
{
    /**
     * `requiresConfirmation` tracks `$mutating` here because the gate is scoped to acts that
     * already needed a human to say yes — not to everything that writes. A mutating tool that
     * never asked for confirmation is unattended work by design, and must stay runnable.
     */
    private function tool(bool $mutating): ToolDefinition
    {
        return new ToolDefinition(
            name: 'plugins.remove',
            description: 'Removes a plugin',
            inputSchema: [],
            callback: static fn (): null => null,
            scopes: [],
            mutating: $mutating,
            requiresConfirmation: $mutating,
        );
    }

    private function signedContext(): ToolContext
    {
        return ToolContext::authorizedBy(
            new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', 'Rodrigo Vicente <rodrigo@teamx.agency>'),
            [],
        );
    }

    public function test_a_mutating_call_from_an_unsigned_shell_is_refused(): void
    {
        $decision = (new PolicyGate())->authorize(ToolContext::cli(), $this->tool(mutating: true));

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('signature naming this call', (string) $decision->reason);
    }

    public function test_a_mutating_call_that_never_asked_for_confirmation_stays_unattended(): void
    {
        // A deploy script or a cron job mutates by design and has no hand to touch a card.
        // Demanding a signature here would force the gate to be switched off wholesale, which is
        // how a real protection becomes an ornament.
        $unattended = new ToolDefinition(
            name: 'cache.warm',
            description: 'Warms the cache',
            inputSchema: [],
            callback: static fn (): null => null,
            scopes: [],
            mutating: true,
            requiresConfirmation: false,
        );

        self::assertTrue((new PolicyGate())->authorize(ToolContext::cli(), $unattended)->allowed);
    }

    public function test_reading_from_an_unsigned_shell_still_works(): void
    {
        // The other half of the decision, and the reason this is not simply `require_auth`.
        // Whoever holds a shell already reads the database; making them touch a card to list
        // plugins buys nothing and gets switched off, which is how gates become ornaments.
        $decision = (new PolicyGate())->authorize(ToolContext::cli(), $this->tool(mutating: false));

        self::assertTrue($decision->allowed);
    }

    public function test_a_mutating_call_carrying_a_verified_signature_proceeds(): void
    {
        $decision = (new PolicyGate())->authorize($this->signedContext(), $this->tool(mutating: true));

        self::assertTrue($decision->allowed);
    }

    public function test_the_gate_reads_the_fingerprint_and_not_the_principal_text(): void
    {
        // Someone could name themselves anything. The fingerprint is only present because a
        // signature verified, so that is what the gate looks at — a context whose principal merely
        // says it is a key gets refused like any other unsigned call.
        $impersonating = new ToolContext(
            principal: 'BE7554E982E2CA5A0213B6067D72DEBDA1D36D34 (Rodrigo Vicente)',
            channel: 'cli',
            scopes: ['*'],
        );

        $decision = (new PolicyGate())->authorize($impersonating, $this->tool(mutating: true));

        self::assertFalse($decision->allowed, 'Looking like a fingerprint is not carrying one.');
    }

    public function test_an_empty_fingerprint_does_not_pass_for_a_signature(): void
    {
        $blank = new ToolContext(
            principal: 'someone',
            channel: 'cli',
            scopes: ['*'],
            extra: ['signer.fingerprint' => ''],
        );

        self::assertFalse((new PolicyGate())->authorize($blank, $this->tool(mutating: true))->allowed);
    }
}
