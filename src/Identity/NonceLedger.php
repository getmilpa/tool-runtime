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
 * Remembers which authorizations have been spent, for as long as they could still be replayed.
 *
 * Only needs to remember for the freshness window: past it, the authorizer rejects on age anyway,
 * so a ledger that grows forever is storing proof of something already impossible.
 */
interface NonceLedger
{
    /**
     * Marks the nonce used and reports whether this call is the one that used it.
     *
     * Must be atomic. A check-then-write leaves a gap where two processes both read "unused" and
     * both proceed — and the operations worth signing are precisely the ones worth not running
     * twice.
     */
    public function spend(string $nonce, int $ttlSeconds, int $now): bool;
}
