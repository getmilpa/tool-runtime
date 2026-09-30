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

namespace Milpa\ToolRuntime\Identity;

/**
 * Verifies a detached OpenPGP signature by asking the `gpg` already on the machine.
 *
 * Shelling out rather than binding ext-gnupg: the extension is rarely installed, while the binary
 * is on every host where an operator has a key at all — and it is the same `gpg` that verifies the
 * project's releases, so the operator surface and the supply chain answer "who signed this" through
 * one implementation instead of two that can drift.
 *
 * The reading is done on the machine-readable status stream, never on the human-facing text. That
 * text is localized: on this machine it says *Firma correcta*, and a verifier that greps for "Good
 * signature" would silently accept every signature in a Spanish locale by finding nothing to
 * object to. `--status-fd` emits `GOODSIG` and `VALIDSIG` in every language.
 */
final class GnupgSignatureVerifier implements SignatureVerifier, ExplainsRefusal
{
    public function __construct(private readonly string $gpgBinary = 'gpg')
    {
    }

    /**
     * Hands both halves to gpg and reads its machine-readable verdict: the signer, or nobody.
     */
    public function verify(string $payload, string $signature): ?VerifiedSigner
    {
        $status = $this->status($payload, $signature);

        return $status === null ? null : $this->readStatus($status);
    }

    /**
     * Why this signature establishes nobody, or null when it does (greenhouse evidence/1071, B9).
     *
     * Asked only after {@see verify()} refused, so gpg runs a second time on the refusal path alone. It
     * reads the same status stream and never returns a signer: the gate's answer is already «no».
     */
    public function whyNot(string $payload, string $signature): ?SignatureRefusal
    {
        $status = $this->status($payload, $signature);
        if ($status === null) {
            return new SignatureRefusal(SignatureRefusal::UNREADABLE);
        }
        if ($this->readStatus($status) !== null) {
            return null;
        }

        return $this->readRefusal($status);
    }

    /**
     * Hands both halves to gpg on disk and returns its status stream, or null when gpg gave none.
     *
     * Temporary files rather than stdin because a detached signature needs two inputs, and both are
     * removed whatever happens — the payload names an operation and its arguments, so leaving it
     * behind would leak what an operator was about to do.
     */
    private function status(string $payload, string $signature): ?string
    {
        $payloadFile = tempnam(sys_get_temp_dir(), 'milpa-op-');
        $signatureFile = tempnam(sys_get_temp_dir(), 'milpa-sig-');
        if ($payloadFile === false || $signatureFile === false) {
            return null;
        }

        try {
            file_put_contents($payloadFile, $payload);
            file_put_contents($signatureFile, $signature);

            $command = escapeshellcmd($this->gpgBinary)
                . ' --batch --no-tty --status-fd 1 --verify '
                . escapeshellarg($signatureFile) . ' ' . escapeshellarg($payloadFile)
                . ' 2>/dev/null';

            $output = shell_exec($command);
            if (!\is_string($output)) {
                return null;
            }

            return $output;
        } finally {
            @unlink($payloadFile);
            @unlink($signatureFile);
        }
    }

    /**
     * Turns gpg's status stream into a signer, or into nothing.
     *
     * `VALIDSIG` carries the full fingerprint of the key that signed and is the only line worth
     * keying an audit record on — `GOODSIG` reports a short key id, and short ids are not unique.
     * Both must be present: `GOODSIG` without `VALIDSIG` is a signature gpg could read but not
     * fully validate, which establishes nothing.
     */
    private function readStatus(string $status): ?VerifiedSigner
    {
        if (!preg_match('/^\[GNUPG:\] VALIDSIG ([0-9A-F]+)/m', $status, $valid)) {
            return null;
        }
        if (!preg_match('/^\[GNUPG:\] GOODSIG \S+ (.*)$/m', $status, $good)) {
            return null;
        }

        $uid = trim($good[1]);

        return new VerifiedSigner(
            fingerprint: $valid[1],
            uid: $uid !== '' ? $uid : null,
        );
    }

    /**
     * Names the refusal from the status stream. A missing key first: gpg prints ERRSIG with return code 9
     * (and, since 2.2, the full fingerprint as its last field) next to NO_PUBKEY with the short id.
     */
    private function readRefusal(string $status): SignatureRefusal
    {
        if (preg_match('/^\[GNUPG:\] ERRSIG (\S+) \S+ \S+ \S+ \S+ 9(?: ([0-9A-F]{40}))?/m', $status, $errsig)) {
            return new SignatureRefusal(SignatureRefusal::MISSING_KEY, ($errsig[2] ?? '') !== '' ? $errsig[2] : $errsig[1], self::keyring());
        }
        if (preg_match('/^\[GNUPG:\] NO_PUBKEY (\S+)/m', $status, $missing)) {
            return new SignatureRefusal(SignatureRefusal::MISSING_KEY, $missing[1], self::keyring());
        }
        foreach ([
            'BADSIG' => SignatureRefusal::ALTERED,
            'EXPKEYSIG' => SignatureRefusal::KEY_EXPIRED,
            'REVKEYSIG' => SignatureRefusal::KEY_REVOKED,
            'EXPSIG' => SignatureRefusal::SIGNATURE_EXPIRED,
        ] as $line => $reason) {
            if (preg_match('/^\[GNUPG:\] ' . $line . ' (\S+)/m', $status, $named)) {
                return new SignatureRefusal($reason, $named[1]);
            }
        }

        return new SignatureRefusal(SignatureRefusal::UNREADABLE);
    }

    /** The keyring gpg read: `GNUPGHOME` when set, otherwise gpg's own default under the home directory. */
    private static function keyring(): string
    {
        $home = getenv('GNUPGHOME');
        if (\is_string($home) && $home !== '') {
            return $home;
        }
        $user = getenv('HOME');

        return (\is_string($user) && $user !== '' ? rtrim($user, '/') : '~') . '/.gnupg';
    }
}
