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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the audit log records for a local call, before and after somebody proves who they are.
 *
 * These two factories are where the surface's honesty is decided, so what they put in `principal`
 * is worth pinning: one of them must not name a person, and the other must name a key.
 */
#[CoversClass(ToolContext::class)]
#[CoversClass(VerifiedSigner::class)]
final class SignedContextTest extends TestCase
{
    public function test_an_unproven_shell_is_named_a_shell_and_not_a_person(): void
    {
        // The previous version scraped the OS for a username, which read like an identity and was
        // a fact about a process. `local-shell` is not a downgrade in information — it is the same
        // information, spelled so nobody mistakes it for testimony about a human.
        $context = ToolContext::cli();

        self::assertSame('local-shell', $context->principal);
        self::assertSame('cli', $context->channel);
    }

    public function test_a_signed_call_is_attributed_to_the_key_that_signed_it(): void
    {
        $signer = new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', 'Rodrigo Vicente <rodrigo@teamx.agency>');

        $context = ToolContext::authorizedBy($signer, ['plugins:write']);

        // Fingerprint first: an auditor months later can re-verify against it, which is the whole
        // difference between a record and a claim.
        self::assertStringStartsWith('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', (string) $context->principal);
        self::assertSame('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', $context->extra['signer.fingerprint']);
    }

    public function test_a_signature_grants_the_operation_it_named_and_not_everything(): void
    {
        // The old cli() handed out ['*'] to a caller it could not name. A signature authorizes one
        // operation, so the grant is exactly that operation's requirements — anything wider would
        // be reading consent for one act as consent for the surface.
        $context = ToolContext::authorizedBy(
            new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34'),
            ['plugins:write'],
        );

        self::assertTrue($context->hasScope('plugins:write'));
        self::assertFalse($context->hasScope('settings:write'));
        self::assertFalse($context->hasAllScopes(['plugins:write', 'settings:write']));
    }

    public function test_a_key_without_a_uid_is_still_a_usable_actor(): void
    {
        // A uid is what the keyholder typed; the fingerprint is derived from the key. Missing the
        // first changes how the line reads, never whether it identifies.
        $context = ToolContext::authorizedBy(new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34'), []);

        self::assertSame('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', $context->principal);
        self::assertNull($context->extra['signer.uid']);
    }

    public function test_plan_mode_survives_the_signature(): void
    {
        $context = ToolContext::authorizedBy(
            new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34'),
            ['plugins:write'],
            mode: 'plan',
        );

        self::assertTrue($context->isPlanMode());
        self::assertFalse($context->isExecuteMode());
    }
}
