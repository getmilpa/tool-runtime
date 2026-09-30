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
 * Why a signature established nobody — in words a person can act on, never as a softer «no».
 *
 * {@see SignatureVerifier::verify()} answers null for every refusal on purpose: an invalid signature and
 * an unknown key mean the same thing to the gate. This names WHICH one it was, for the sentence only. It
 * carries no signer and grants nothing; a caller that reads it has already been refused.
 *
 * It exists because one sentence covered them all. A receipt signed by a key that was simply not in the
 * keyring the terminal read was reported as «altered, or the key expired or was revoked», and the person
 * went looking for tampering instead of switching keyrings (greenhouse evidence/1071, B9).
 */
final readonly class SignatureRefusal
{
    /** The signing key is not in the keyring that was read (gpg: ERRSIG rc 9 / NO_PUBKEY). */
    public const string MISSING_KEY = 'missing_key';

    /** The signature does not match the bytes (gpg: BADSIG). */
    public const string ALTERED = 'altered';

    /** The signing key has expired (gpg: EXPKEYSIG). */
    public const string KEY_EXPIRED = 'key_expired';

    /** The signing key was revoked (gpg: REVKEYSIG). */
    public const string KEY_REVOKED = 'key_revoked';

    /** The signature itself has expired (gpg: EXPSIG). */
    public const string SIGNATURE_EXPIRED = 'signature_expired';

    /** gpg gave no verdict this can read: no binary, no status lines, or a status not listed here. */
    public const string UNREADABLE = 'unreadable';

    /**
     * @param string      $reason  one of the constants above
     * @param string|null $key     the key gpg named — the full fingerprint when gpg printed one
     * @param string|null $keyring the keyring that was read, when the refusal is about it
     */
    public function __construct(
        public string $reason,
        public ?string $key = null,
        public ?string $keyring = null,
    ) {
    }

    /** What happened, as a clause that completes «its signature does not verify: …». */
    public function sentence(): string
    {
        $key = $this->key !== null && $this->key !== '' ? ' (' . $this->key . ')' : '';

        return match ($this->reason) {
            self::MISSING_KEY => 'the key that signed it' . $key . ' is not in the keyring this terminal reads'
                . ($this->keyring !== null && $this->keyring !== '' ? ' (' . $this->keyring . ')' : ''),
            self::ALTERED => 'the signature does not match what it signed — the receipt was altered',
            self::KEY_EXPIRED => 'the key that signed it' . $key . ' has expired',
            self::KEY_REVOKED => 'the key that signed it' . $key . ' was revoked',
            self::SIGNATURE_EXPIRED => 'the signature itself has expired',
            default => 'gpg gave no verdict that could be read',
        };
    }

    /** What to do next — the one step that fits this refusal, not a list of possibilities. */
    public function remedy(): string
    {
        return match ($this->reason) {
            self::MISSING_KEY => 'Run it again with GNUPGHOME set to the keyring of the key that signed it — for a resident, the resident\'s own keyring.',
            self::UNREADABLE => 'Check that gpg is installed and answers `gpg --version` in this terminal.',
            default => 'Re-run with --sign to open the sequence again under a signature of today.',
        };
    }
}
