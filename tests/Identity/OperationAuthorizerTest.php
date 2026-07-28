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

use Milpa\ToolRuntime\Identity\AuthorizationVerdict;
use Milpa\ToolRuntime\Identity\NonceLedger;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * When a signature authorizes a call, and the four separate ways it does not.
 *
 * A valid signature answers *who* and nothing else. Each test below removes one of the other
 * conditions and requires a refusal — because a signature that is merely checked, without asking
 * what it says or when it was said, produces the expensive failure: something that passes review
 * for having a signature and authorizes an act nobody consented to.
 */
#[CoversClass(OperationAuthorizer::class)]
#[CoversClass(OperationAuthorization::class)]
#[CoversClass(AuthorizationVerdict::class)]
#[CoversClass(VerifiedSigner::class)]
final class OperationAuthorizerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function verifier(?VerifiedSigner $result): SignatureVerifier
    {
        return new class ($result) implements SignatureVerifier {
            public function __construct(private readonly ?VerifiedSigner $result)
            {
            }

            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return $this->result;
            }
        };
    }

    private function ledger(bool $accepts = true): NonceLedger
    {
        return new class ($accepts) implements NonceLedger {
            /** @var list<string> */
            public array $spent = [];

            public function __construct(private readonly bool $accepts)
            {
            }

            public function spend(string $nonce, int $ttlSeconds, int $now): bool
            {
                $this->spent[] = $nonce;

                return $this->accepts;
            }
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function payload(
        string $operation = 'plugins.remove',
        array $arguments = ['name' => 'MailPlugin'],
        string $host = 'cm4070',
        ?string $issuedAt = null,
    ): string {
        return (new OperationAuthorization(
            operation: $operation,
            arguments: $arguments,
            host: $host,
            issuedAt: $issuedAt ?? gmdate('c', self::NOW),
            nonce: 'b7f3c1a9d2e84f60',
        ))->canonical();
    }

    private function authorizer(?VerifiedSigner $signer, ?NonceLedger $ledger = null): OperationAuthorizer
    {
        return new OperationAuthorizer($this->verifier($signer), $ledger ?? $this->ledger(), 120);
    }

    private function signer(): VerifiedSigner
    {
        return new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', 'Rodrigo Vicente <rodrigo@teamx.agency>');
    }

    public function test_a_signature_over_this_exact_call_authorizes_it(): void
    {
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'cm4070',
            $this->payload(),
            'signature',
            self::NOW,
        );

        self::assertTrue($verdict->granted);
        self::assertSame('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', $verdict->signer?->fingerprint);
    }

    public function test_no_signer_means_no_authorization(): void
    {
        // The port returns null for both a bad signature and an unknown key, and the caller must
        // not be able to tell which — either way nothing was established.
        $verdict = $this->authorizer(null)->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'cm4070',
            $this->payload(),
            'signature',
            self::NOW,
        );

        self::assertFalse($verdict->granted);
        self::assertStringContainsString('does not verify', (string) $verdict->reason);
    }

    public function test_an_authorization_for_another_target_does_not_authorize_this_one(): void
    {
        // The reason --yes had to go. Consent to remove MailPlugin is not consent to remove
        // BillingPlugin, and a flag cannot tell them apart because it never knew the target.
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['name' => 'BillingPlugin'],
            'cm4070',
            $this->payload(arguments: ['name' => 'MailPlugin']),
            'signature',
            self::NOW,
        );

        self::assertFalse($verdict->granted);
        self::assertStringContainsString('different call', (string) $verdict->reason);
    }

    public function test_an_authorization_for_another_operation_does_not_authorize_this_one(): void
    {
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'cm4070',
            $this->payload(operation: 'plugins.disable'),
            'signature',
            self::NOW,
        );

        self::assertFalse($verdict->granted);
    }

    public function test_an_authorization_for_another_host_does_not_authorize_this_one(): void
    {
        // Signed against staging, presented in production. Without the host in the payload these
        // are the same bytes.
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'milpa-prod-1',
            $this->payload(host: 'cm4070'),
            'signature',
            self::NOW,
        );

        self::assertFalse($verdict->granted);
        self::assertStringContainsString('cm4070', (string) $verdict->reason);
    }

    public function test_an_expired_authorization_is_refused_with_what_to_do_about_it(): void
    {
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'cm4070',
            $this->payload(),
            'signature',
            self::NOW + 300,
        );

        self::assertFalse($verdict->granted);
        self::assertStringContainsString('sign the operation again', (string) $verdict->reason);
    }

    public function test_an_authorization_inside_the_window_is_still_good(): void
    {
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'cm4070',
            $this->payload(),
            'signature',
            self::NOW + 119,
        );

        self::assertTrue($verdict->granted);
    }

    public function test_an_authorization_stamped_in_the_future_is_refused(): void
    {
        // A clock ahead of ours is broken or being helped. 'Very fresh' is not a reading.
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'cm4070',
            $this->payload(),
            'signature',
            self::NOW - 60,
        );

        self::assertFalse($verdict->granted);
        self::assertStringContainsString('future', (string) $verdict->reason);
    }

    public function test_a_reused_authorization_is_refused_even_while_fresh(): void
    {
        $verdict = $this->authorizer($this->signer(), $this->ledger(accepts: false))->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'cm4070',
            $this->payload(),
            'signature',
            self::NOW,
        );

        self::assertFalse($verdict->granted);
        self::assertStringContainsString('already used', (string) $verdict->reason);
    }

    public function test_a_refusal_never_spends_the_nonce(): void
    {
        // Spending is the only side effect here. If a call refused for age or mismatch burned the
        // nonce, an attacker could invalidate an operator's authorization by presenting it wrong
        // first — a denial of service built out of the replay defence.
        $ledger = $this->ledger();
        $this->authorizer($this->signer(), $ledger)->authorize(
            'plugins.remove',
            ['name' => 'BillingPlugin'],
            'cm4070',
            $this->payload(arguments: ['name' => 'MailPlugin']),
            'signature',
            self::NOW,
        );

        self::assertSame([], $ledger->spent, 'A call refused before the nonce check must not consume it.');
    }

    public function test_a_signed_payload_that_is_not_an_authorization_is_refused(): void
    {
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['name' => 'MailPlugin'],
            'cm4070',
            '{"hello":"world"}',
            'signature',
            self::NOW,
        );

        self::assertFalse($verdict->granted);
        self::assertStringContainsString('not an operation authorization', (string) $verdict->reason);
    }

    public function test_argument_order_does_not_change_the_authorization(): void
    {
        // Same call, different key order from the caller. A signature is over bytes, so the
        // canonical form has to be the same or every honest authorization fails.
        $verdict = $this->authorizer($this->signer())->authorize(
            'plugins.remove',
            ['b' => 2, 'a' => 1],
            'cm4070',
            $this->payload(arguments: ['a' => 1, 'b' => 2]),
            'signature',
            self::NOW,
        );

        self::assertTrue($verdict->granted, 'Key order is a spelling difference, not a different call.');
    }

    public function test_list_order_is_part_of_the_call_and_is_never_sorted(): void
    {
        // Maps have no meaningful key order, so sorting them is harmless. Lists do: sorting
        // ['drop','create'] into ['create','drop'] would make a signature over one sequence
        // authorize the other, which is the same defect the target check exists to prevent.
        $verdict = $this->authorizer($this->signer())->authorize(
            'schema.apply',
            ['steps' => ['create', 'drop']],
            'cm4070',
            $this->payload(operation: 'schema.apply', arguments: ['steps' => ['drop', 'create']]),
            'signature',
            self::NOW,
        );

        self::assertFalse($verdict->granted, 'A different order is a different operation.');
    }

    public function test_nested_map_order_is_a_spelling_difference_at_every_depth(): void
    {
        $verdict = $this->authorizer($this->signer())->authorize(
            'schema.apply',
            ['target' => ['table' => 'users', 'column' => 'email']],
            'cm4070',
            $this->payload(operation: 'schema.apply', arguments: ['target' => ['column' => 'email', 'table' => 'users']]),
            'signature',
            self::NOW,
        );

        self::assertTrue($verdict->granted);
    }

    public function test_bytes_that_are_not_json_are_not_an_authorization(): void
    {
        self::assertNull(OperationAuthorization::fromCanonical('no soy json'));
        self::assertNull(OperationAuthorization::fromCanonical('"una cadena suelta"'));
    }

    public function test_a_missing_or_blank_field_is_not_an_authorization(): void
    {
        // A blank host or nonce would compare equal to another blank one, so an authorization
        // missing either is not a weaker authorization — it is a different document.
        self::assertNull(OperationAuthorization::fromCanonical('{"operation":"x","host":"h","issuedAt":"t"}'));
        self::assertNull(OperationAuthorization::fromCanonical('{"operation":"x","arguments":{},"host":"","issuedAt":"t","nonce":"n"}'));
    }

    public function test_arguments_must_be_a_structure_not_a_scalar(): void
    {
        self::assertNull(OperationAuthorization::fromCanonical('{"operation":"x","arguments":"todo","host":"h","issuedAt":"t","nonce":"n"}'));
    }

    public function test_an_unreadable_timestamp_is_infinitely_old_not_infinitely_fresh(): void
    {
        // The failure has to fall on the safe side: a date nobody can parse must expire, never
        // pass. Returning 0 here would make every malformed stamp permanently valid.
        $authorization = new OperationAuthorization('op', [], 'h', 'no es una fecha', 'n');

        self::assertSame(\PHP_INT_MAX, $authorization->ageInSeconds(1_800_000_000));
    }

    public function test_the_audit_actor_leads_with_the_fingerprint(): void
    {
        // The uid is whatever the keyholder typed when creating the key; the fingerprint is derived
        // from the key material. Only one of them is safe to key a record on.
        self::assertStringStartsWith('BE7554E9', $this->signer()->principal());
        self::assertStringContainsString('rodrigo@teamx.agency', $this->signer()->principal());
    }
}
