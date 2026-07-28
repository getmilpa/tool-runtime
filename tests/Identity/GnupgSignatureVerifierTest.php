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
            "[GNUPG:] GOODSIG 7D72DEBDA1D36D34 Rodrigo Vicente (TeamX Admin) <rodrigo@teamx.agency>\n" .
            '[GNUPG:] VALIDSIG BE7554E982E2CA5A0213B6067D72DEBDA1D36D34 2026-07-28 1785000000'
        );

        $signer = (new GnupgSignatureVerifier($gpg))->verify('payload', 'signature');

        // The short id from GOODSIG is not unique; VALIDSIG carries the whole fingerprint, and an
        // audit record keyed on the short one can be collided with on purpose.
        self::assertSame('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', $signer?->fingerprint);
        self::assertSame('Rodrigo Vicente (TeamX Admin) <rodrigo@teamx.agency>', $signer?->uid);
    }

    public function test_a_bad_signature_establishes_nobody(): void
    {
        $gpg = $this->gpgPrinting('[GNUPG:] BADSIG 7D72DEBDA1D36D34 Rodrigo Vicente <rodrigo@teamx.agency>');

        self::assertNull((new GnupgSignatureVerifier($gpg))->verify('payload', 'signature'));
    }

    public function test_an_unknown_key_establishes_nobody(): void
    {
        $gpg = $this->gpgPrinting("[GNUPG:] NO_PUBKEY 7D72DEBDA1D36D34\n[GNUPG:] ERRSIG 7D72DEBDA1D36D34 1 8 00 1785000000 9");

        self::assertNull((new GnupgSignatureVerifier($gpg))->verify('payload', 'signature'));
    }

    public function test_a_signature_read_but_not_validated_establishes_nobody(): void
    {
        // GOODSIG without VALIDSIG: gpg could parse it and could not fully validate it. Half a
        // verdict is not a verdict.
        $gpg = $this->gpgPrinting('[GNUPG:] GOODSIG 7D72DEBDA1D36D34 Rodrigo Vicente <rodrigo@teamx.agency>');

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
        $gpg = $this->gpgPrinting('[GNUPG:] VALIDSIG BE7554E982E2CA5A0213B6067D72DEBDA1D36D34 2026-07-28 1785000000');

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
}
