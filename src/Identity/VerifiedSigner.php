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
 * Who a valid signature established.
 *
 * The fingerprint is the identity; the uid is how a human recognises it. They are kept apart
 * because only one of them is a fact: a key can carry any uid its holder typed when creating it,
 * while the fingerprint is derived from the key material and cannot be chosen to impersonate
 * another. So the fingerprint is what an audit line is keyed on, and the uid is what it displays.
 *
 * This is the same distinction that ran through the whole surface — established against claimed —
 * arriving at the place where it costs nothing to get right and everything to get wrong.
 */
final readonly class VerifiedSigner
{
    public function __construct(
        public string $fingerprint,
        public ?string $uid = null,
    ) {
    }

    /**
     * What the audit log records as the actor.
     *
     * Fingerprint first, because that is the part a verifier can act on months later; the uid rides
     * along in parentheses for whoever is reading rather than querying.
     */
    public function principal(): string
    {
        return $this->uid !== null && $this->uid !== ''
            ? $this->fingerprint . ' (' . $this->uid . ')'
            : $this->fingerprint;
    }
}
