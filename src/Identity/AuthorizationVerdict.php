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
 * The answer, with the reason attached when it is no.
 *
 * A boolean would force the caller to invent the explanation, and the four ways an authorization
 * fails are not interchangeable to whoever is standing at the terminal: a bad signature means stop,
 * an expired one means sign again, a reused one means something is replaying, and a mismatched one
 * means the authorization is for a different act than the one about to run.
 */
final readonly class AuthorizationVerdict
{
    private function __construct(
        public bool $granted,
        public ?VerifiedSigner $signer,
        public ?string $reason,
    ) {
    }

    /** The signature established this signer, for this call, now, once. */
    public static function granted(VerifiedSigner $signer): self
    {
        return new self(true, $signer, null);
    }

    /** Refused, with the sentence the operator needs to read. */
    public static function denied(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
