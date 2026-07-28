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
 * Decides whether a signed authorization actually authorizes this call, right now, once.
 *
 * A valid signature answers only *who*. Three more things have to hold before it authorizes
 * anything, and each is a separate way of being wrong:
 *
 * 1. **It names this call.** The payload carries the operation, its arguments and the host. The
 *    caller states what it is about to run; if the signed bytes describe something else, the
 *    signature is someone's authorization for a different act.
 * 2. **It is fresh.** A signature is valid forever — that is what signatures are — so without a
 *    window, one captured today authorizes the same operation next year.
 * 3. **It is unused.** Freshness alone still leaves the window: the same bytes work as many times
 *    as they are presented inside it. The nonce is spent on first use.
 *
 * Skipping any one of them yields something that looks like authorization and is not, which is the
 * expensive kind: it passes review because a signature was checked.
 */
final readonly class OperationAuthorizer
{
    /**
     * @param int $freshnessWindowSeconds how long an authorization stays usable after it is issued.
     *                                    Short enough that a captured one is worthless, long enough
     *                                    to survive a card touch and a slow terminal
     */
    public function __construct(
        private SignatureVerifier $verifier,
        private NonceLedger $nonces,
        private int $freshnessWindowSeconds = 120,
    ) {
    }

    /**
     * Grants only when all four hold: valid signature, this call, fresh, unused.
     *
     * The order is deliberate — the nonce is spent last, because it is the only step with a side
     * effect and a call refused for any other reason must leave the authorization usable.
     *
     * @param array<string, mixed> $arguments what the caller is actually about to run
     * @param int                  $now       unix time, injected so freshness is testable
     */
    public function authorize(
        string $operation,
        array $arguments,
        string $host,
        string $signedPayload,
        string $signature,
        int $now,
    ): AuthorizationVerdict {
        $signer = $this->verifier->verify($signedPayload, $signature);
        if ($signer === null) {
            return AuthorizationVerdict::denied('the signature does not verify, or its key is unknown');
        }

        $authorization = OperationAuthorization::fromCanonical($signedPayload);
        if ($authorization === null) {
            // Signed, and not an authorization. Someone presented a validly signed shopping list.
            return AuthorizationVerdict::denied('the signed payload is not an operation authorization');
        }

        // Rebuilt from what the caller is about to do, then compared byte for byte. Comparing field
        // by field would drift the moment a field is added: the new one would go unchecked and the
        // comparison would keep passing, which is worse than not comparing at all.
        $expected = new OperationAuthorization(
            operation: $operation,
            arguments: $arguments,
            host: $host,
            issuedAt: $authorization->issuedAt,
            nonce: $authorization->nonce,
        );

        if (!hash_equals($expected->canonical(), $authorization->canonical())) {
            return AuthorizationVerdict::denied(
                "this authorization is for a different call — signed '{$authorization->operation}' on host '{$authorization->host}'"
            );
        }

        $age = $authorization->ageInSeconds($now);
        if ($age < 0) {
            // Issued in the future: either a broken clock or one being helped. Both are refusals.
            return AuthorizationVerdict::denied('the authorization is stamped in the future');
        }
        if ($age > $this->freshnessWindowSeconds) {
            return AuthorizationVerdict::denied(
                "the authorization expired {$age}s ago — sign the operation again"
            );
        }

        // Last, deliberately: spending the nonce is the one step with a side effect, and it must
        // not be spent by a call that was going to be refused for any other reason.
        if (!$this->nonces->spend($authorization->nonce, $this->freshnessWindowSeconds, $now)) {
            return AuthorizationVerdict::denied('this authorization was already used');
        }

        return AuthorizationVerdict::granted($signer);
    }
}
