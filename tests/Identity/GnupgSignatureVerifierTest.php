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

use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\ToolRuntime\Identity\SignatureRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading gpg's verdict — the step where a verifier most easily agrees with something it did not check.
 *
 * The binary is injected so every outcome can be produced on demand, including the ones a real
 * keyring will not hand over: an unknown key, a signature gpg parses but cannot validate, and the
 * localized output that broke a naive implementation. A verifier that greps the human-facing text
 * for "Good signature" finds nothing on a Spanish machine — where it says *Firma correcta* — and
 * accepting on "no objection found" is how a check becomes decorative in exactly one locale.
 */
#[CoversClass(GnupgSignatureVerifier::class)]
#[CoversClass(SignatureRefusal::class)]
final class GnupgSignatureVerifierTest extends TestCase
{
    /** @var list<string> */
    private array $scripts = [];

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
    }

    /**
     * A stand-in gpg that prints whatever verdict the test needs.
     */
    private function gpgPrinting(string $output): string
    {
        $path = sys_get_temp_dir() . '/fake-gpg-' . bin2hex(random_bytes(6));
        file_put_contents($path, "#!/usr/bin/env bash\ncat <<'EOF'\n{$output}\nEOF\n");
        chmod($path, 0o700);
        $this->scripts[] = $path;

        return $path;
    }

    public function test_a_good_signature_yields_the_full_fingerprint(): void
    {
        $gpg = $this->gpgPrinting(
            "[GNUPG:] GOODSIG DDDD4444EEEE5555 Rodrigo Vicente (TeamX Admin) <rodrigo@teamx.agency>\n" .
            '[GNUPG:] VALIDSIG AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555 2026-07-28 1785000000'
        );

        $signer = (new GnupgSignatureVerifier($gpg))->verify('payload', 'signature');

        // The short id from GOODSIG is not unique; VALIDSIG carries the whole fingerprint, and an
        // audit record keyed on the short one can be collided with on purpose.
        self::assertSame('AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555', $signer?->fingerprint);
        self::assertSame('Rodrigo Vicente (TeamX Admin) <rodrigo@teamx.agency>', $signer?->uid);
    }

    public function test_a_bad_signature_establishes_nobody(): void
    {
        $gpg = $this->gpgPrinting('[GNUPG:] BADSIG DDDD4444EEEE5555 Rodrigo Vicente <rodrigo@teamx.agency>');

        self::assertNull((new GnupgSignatureVerifier($gpg))->verify('payload', 'signature'));
    }

    public function test_an_unknown_key_establishes_nobody(): void
    {
        $gpg = $this->gpgPrinting("[GNUPG:] NO_PUBKEY DDDD4444EEEE5555\n[GNUPG:] ERRSIG DDDD4444EEEE5555 1 8 00 1785000000 9");

        self::assertNull((new GnupgSignatureVerifier($gpg))->verify('payload', 'signature'));
    }

    public function test_a_signature_read_but_not_validated_establishes_nobody(): void
    {
        // GOODSIG without VALIDSIG: gpg could parse it and could not fully validate it. Half a
        // verdict is not a verdict.
        $gpg = $this->gpgPrinting('[GNUPG:] GOODSIG DDDD4444EEEE5555 Rodrigo Vicente <rodrigo@teamx.agency>');

        self::assertNull((new GnupgSignatureVerifier($gpg))->verify('payload', 'signature'));
    }

    public function test_localized_success_text_alone_is_not_accepted(): void
    {
        // What a real Spanish-locale gpg prints on stderr, with no status lines at all. The whole
        // reason this reads --status-fd instead of the prose.
        $gpg = $this->gpgPrinting('gpg: Firma correcta de "Rodrigo Vicente <rodrigo@teamx.agency>"');

        self::assertNull((new GnupgSignatureVerifier($gpg))->verify('payload', 'signature'));
    }

    public function test_a_validated_signature_with_no_signer_line_establishes_nobody(): void
    {
        // VALIDSIG without GOODSIG: the cryptography checked out and gpg named nobody. Both lines
        // are required because each answers half the question, and half an answer here would put
        // an empty actor in an audit record.
        $gpg = $this->gpgPrinting('[GNUPG:] VALIDSIG AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555 2026-07-28 1785000000');

        self::assertNull((new GnupgSignatureVerifier($gpg))->verify('payload', 'signature'));
    }

    public function test_a_missing_binary_establishes_nobody(): void
    {
        self::assertNull(
            (new GnupgSignatureVerifier('/nonexistent/gpg'))->verify('payload', 'signature')
        );
    }

    public function test_it_leaves_no_payload_or_signature_behind(): void
    {
        // The payload names an operation and its arguments; the signature authorizes it. Both are
        // written to disk to be handed to gpg, and neither should outlive the call.
        $before = (array) glob(sys_get_temp_dir() . '/milpa-{op,sig}-*', \GLOB_BRACE);

        $gpg = $this->gpgPrinting('[GNUPG:] BADSIG x y');
        (new GnupgSignatureVerifier($gpg))->verify('payload', 'signature');

        $after = (array) glob(sys_get_temp_dir() . '/milpa-{op,sig}-*', \GLOB_BRACE);
        self::assertSame(\count($before), \count($after));
    }
    /*
     * ── WHY NOT: the refusal named, never a signer (greenhouse evidence/1071 B9) ────────────────────────
     *
     * A receipt signed by a key that is simply not in the keyring was reported as «altered, or the key
     * expired or was revoked». The statuses below are what gpg 2.4.9 printed for each case (evidence/1077).
     */

    public function test_a_key_missing_from_the_keyring_is_named_with_its_fingerprint(): void
    {
        $gpg = $this->gpgPrinting(
            "[GNUPG:] NEWSIG\n"
            . "[GNUPG:] ERRSIG DA8D8D3D7BFE43D3 22 10 00 1790745392 9 B386992484B357EFE18FCA25DA8D8D3D7BFE43D3\n"
            . "[GNUPG:] NO_PUBKEY DA8D8D3D7BFE43D3\n"
            . '[GNUPG:] FAILURE gpg-exit 33554433'
        );

        $refusal = (new GnupgSignatureVerifier($gpg))->whyNot('payload', 'signature');

        self::assertSame(SignatureRefusal::MISSING_KEY, $refusal?->reason);
        self::assertSame('B386992484B357EFE18FCA25DA8D8D3D7BFE43D3', $refusal?->key);
        self::assertStringContainsString('is not in the keyring this terminal reads', $refusal?->sentence() ?? '');
        self::assertStringNotContainsString('altered', $refusal?->sentence() ?? '');
        self::assertStringContainsString('GNUPGHOME', $refusal?->remedy() ?? '');
    }

    public function test_an_older_gpg_that_only_says_no_pubkey_is_named_by_its_short_id(): void
    {
        $gpg = $this->gpgPrinting('[GNUPG:] NO_PUBKEY DDDD4444EEEE5555');

        $refusal = (new GnupgSignatureVerifier($gpg))->whyNot('payload', 'signature');

        self::assertSame(SignatureRefusal::MISSING_KEY, $refusal?->reason);
        self::assertSame('DDDD4444EEEE5555', $refusal?->key);
    }

    public function test_a_bad_signature_is_named_altered(): void
    {
        $gpg = $this->gpgPrinting("[GNUPG:] BADSIG DA8D8D3D7BFE43D3 resident\n[GNUPG:] FAILURE gpg-exit 33554433");

        $refusal = (new GnupgSignatureVerifier($gpg))->whyNot('payload', 'signature');

        self::assertSame(SignatureRefusal::ALTERED, $refusal?->reason);
        self::assertStringContainsString('altered', $refusal?->sentence() ?? '');
        self::assertStringContainsString('--sign', $refusal?->remedy() ?? '');
    }

    public function test_an_expired_or_revoked_key_is_named_as_such(): void
    {
        $expired = $this->gpgPrinting('[GNUPG:] EXPKEYSIG DA8D8D3D7BFE43D3 resident');
        $revoked = $this->gpgPrinting('[GNUPG:] REVKEYSIG DA8D8D3D7BFE43D3 resident');
        $old = $this->gpgPrinting('[GNUPG:] EXPSIG DA8D8D3D7BFE43D3 resident');

        self::assertSame(SignatureRefusal::KEY_EXPIRED, (new GnupgSignatureVerifier($expired))->whyNot('p', 's')?->reason);
        self::assertSame(SignatureRefusal::KEY_REVOKED, (new GnupgSignatureVerifier($revoked))->whyNot('p', 's')?->reason);
        self::assertSame(SignatureRefusal::SIGNATURE_EXPIRED, (new GnupgSignatureVerifier($old))->whyNot('p', 's')?->reason);
        self::assertStringContainsString('expired', (new GnupgSignatureVerifier($expired))->whyNot('p', 's')?->sentence() ?? '');
        self::assertStringContainsString('revoked', (new GnupgSignatureVerifier($revoked))->whyNot('p', 's')?->sentence() ?? '');
    }

    public function test_a_status_it_cannot_read_is_said_as_unreadable_not_as_a_cause(): void
    {
        $gpg = $this->gpgPrinting('gpg: Firma correcta de "Rodrigo Vicente <rodrigo@teamx.agency>"');

        self::assertSame(SignatureRefusal::UNREADABLE, (new GnupgSignatureVerifier($gpg))->whyNot('p', 's')?->reason);
        self::assertSame(SignatureRefusal::UNREADABLE, (new GnupgSignatureVerifier('/nonexistent/gpg'))->whyNot('p', 's')?->reason);
    }

    public function test_a_signature_that_verifies_has_no_refusal(): void
    {
        $gpg = $this->gpgPrinting(
            "[GNUPG:] GOODSIG DDDD4444EEEE5555 Rodrigo Vicente <rodrigo@teamx.agency>\n"
            . '[GNUPG:] VALIDSIG AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555 2026-07-28 1785000000'
        );

        self::assertNull((new GnupgSignatureVerifier($gpg))->whyNot('payload', 'signature'));
    }

    public function test_the_refusal_never_establishes_a_signer(): void
    {
        // The port's rule stands (SignatureVerifier): verify() says null for every refusal. whyNot() only
        // puts words to it; a missing key must not become a softer «no» a caller could proceed on.
        $gpg = $this->gpgPrinting("[GNUPG:] ERRSIG DA8D8D3D7BFE43D3 22 10 00 1790745392 9 B386992484B357EFE18FCA25DA8D8D3D7BFE43D3\n[GNUPG:] NO_PUBKEY DA8D8D3D7BFE43D3");

        self::assertNull((new GnupgSignatureVerifier($gpg))->verify('payload', 'signature'));
    }

    public function test_the_missing_key_sentence_names_the_keyring_it_read(): void
    {
        $refusal = new SignatureRefusal(SignatureRefusal::MISSING_KEY, 'B386992484B357EFE18FCA25DA8D8D3D7BFE43D3', '/home/operator/.gnupg');

        self::assertSame(
            'the key that signed it (B386992484B357EFE18FCA25DA8D8D3D7BFE43D3) is not in the keyring this terminal reads (/home/operator/.gnupg)',
            $refusal->sentence(),
        );
        self::assertSame(
            'Run it again with GNUPGHOME set to the keyring of the key that signed it — for a resident, the resident\'s own keyring.',
            $refusal->remedy(),
        );
    }
}
