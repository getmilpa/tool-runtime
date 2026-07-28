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
 * Says who signed some bytes, or refuses to say.
 *
 * A port, not a convenience: verification is the one step here that leaves the process — it reads a
 * keyring, and in other hosts it might read a database of enrolled keys or a hardware module
 * instead. Behind an interface, the authorization rules can be tested against every outcome that
 * matters (good signature, bad signature, unknown key) without a keyring existing anywhere near the
 * test, and a host can replace the mechanism without touching the policy.
 */
interface SignatureVerifier
{
    /**
     * The signer, or null when the signature does not establish one.
     *
     * Null covers two situations that must never be told apart *here*: the signature is invalid,
     * and the key is unknown. Both mean the same thing to the caller — nothing was established —
     * and an implementation that leaked the difference would let a caller decide to proceed anyway
     * on the softer one, which is exactly the decision this port exists to remove.
     */
    public function verify(string $payload, string $signature): ?VerifiedSigner;
}
