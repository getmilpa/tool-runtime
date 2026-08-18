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

use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a granted verdict carries into the run.
 *
 * The interesting property is byte-exactness: a consumer of this record re-verifies the signature
 * over the payload (greenhouse evidence/0254), and re-verification only means something if the
 * bytes it gets are the bytes that were signed — not a re-serialization, however faithful.
 */
#[CoversClass(GrantedAuthorization::class)]
final class GrantedAuthorizationTest extends TestCase
{
    public function test_it_exposes_everything_the_verdict_established(): void
    {
        $authorization = new OperationAuthorization(
            operation: 'session.own',
            arguments: ['session' => 'abc123'],
            host: 'greenhouse-host',
            issuedAt: '2026-08-18T12:00:00+00:00',
            nonce: 'deadbeefdeadbeef',
        );
        $signer = new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', 'Rodrigo Vicente <rodrigo@teamx.agency>');
        $payload = $authorization->canonical();
        $signature = '-----BEGIN PGP SIGNATURE----- the armored block as gpg emitted it';

        $granted = new GrantedAuthorization($authorization, $signer, $payload, $signature);

        self::assertSame($authorization, $granted->authorization);
        self::assertSame($signer, $granted->signer);
        self::assertSame('session.own', $granted->authorization->operation);
        self::assertSame('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', $granted->signer->fingerprint);
    }

    public function test_payload_and_signature_are_the_exact_bytes_given_and_not_a_paraphrase(): void
    {
        // The payload here is deliberately NOT the canonical form of the authorization: extra
        // whitespace, keys out of order. The record must keep it anyway, byte for byte — the
        // moment it "helpfully" re-canonicalizes, a consumer re-verifying the signature is
        // verifying bytes that were never signed.
        $payload = "{ \"operation\": \"session.own\",\n  \"arguments\": {} }";
        $signature = "raw signature bytes \x00\x01 with binary in them";

        $granted = new GrantedAuthorization(
            authorization: new OperationAuthorization('session.own', [], 'host', '2026-08-18T12:00:00+00:00', 'nonce-1'),
            signer: new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34'),
            payload: $payload,
            signature: $signature,
        );

        self::assertSame($payload, $granted->payload);
        self::assertSame($signature, $granted->signature);
    }
}
