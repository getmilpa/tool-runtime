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
final class GnupgSignatureVerifier implements SignatureVerifier
{
    public function __construct(private readonly string $gpgBinary = 'gpg')
    {
    }

    /**
     * Hands both halves to gpg on disk and reads its machine-readable verdict.
     *
     * Temporary files rather than stdin because a detached signature needs two inputs, and both are
     * removed whatever happens — the payload names an operation and its arguments, so leaving it
     * behind would leak what an operator was about to do.
     */
    public function verify(string $payload, string $signature): ?VerifiedSigner
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

            return $this->readStatus($output);
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
}
