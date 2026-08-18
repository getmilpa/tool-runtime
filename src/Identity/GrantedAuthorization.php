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
 * A granted verdict, packed so it can travel into the run.
 *
 * Until now the verdict died at the banner: the gate printed "authorized by ..." and dropped the
 * signer, so by the time the handler ran, the fact that a key had answered — and which one, and
 * over which bytes — was gone. A handler that wants to PERSIST that fact as an assertion (e.g.
 * `session:own`, greenhouse decisions/0056) had nothing to persist but its own retelling.
 *
 * So this carries the whole grant, and every field is load-bearing:
 *
 * - `authorization` — the parsed claim: which operation, which arguments, which host, when, which
 *   nonce. What the handler reads to know what was consented to.
 * - `signer` — who the verdict established, by fingerprint. The actor the assertion names.
 * - `payload` and `signature` — the RAW bytes, exactly as signed and exactly as presented. Raw
 *   because the receipt doctrine (greenhouse evidence/0254) requires a consumer to RE-VERIFY
 *   rather than trust this record's word, and you cannot re-verify a paraphrase: a
 *   re-serialization of `authorization`, however faithful, is a different byte string and the
 *   signature over it proves nothing.
 *
 * The parsed and the raw forms ride together on purpose. Only the raw pair is evidence; the
 * parsed form is convenience, and keeping them side by side means a consumer never has to choose
 * between reading comfortably and verifying honestly.
 */
final readonly class GrantedAuthorization
{
    public function __construct(
        public OperationAuthorization $authorization,
        public VerifiedSigner $signer,
        public string $payload,
        public string $signature,
    ) {
    }
}
