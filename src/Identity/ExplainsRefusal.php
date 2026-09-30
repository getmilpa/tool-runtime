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
 * A verifier that can say WHY a signature established nobody — after the gate has already refused.
 *
 * Kept apart from {@see SignatureVerifier} so that port's rule stands untouched: `verify()` is the only
 * answer a gate reads, and it is null for every refusal. This one returns words for a person, never a
 * signer, so there is no softer «no» a caller could decide to proceed on (greenhouse evidence/1071, B9).
 */
interface ExplainsRefusal
{
    /**
     * The refusal, named — or null when the signature does establish a signer.
     */
    public function whyNot(string $payload, string $signature): ?SignatureRefusal;
}
