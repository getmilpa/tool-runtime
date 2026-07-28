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
 * A spent-nonce ledger backed by one file per nonce.
 *
 * Atomicity comes from the filesystem rather than from this class: `fopen(..., 'x')` creates the
 * file only if it does not exist, and the kernel settles the race. A `file_exists()` followed by a
 * write would read correctly in every test and lose to two shells pressing enter together — the
 * exact case the ledger is for, since an authorization worth signing is one worth not running
 * twice.
 *
 * Hosts with more than one machine want a shared implementation; this one is honest about being
 * per-host, and per-host is the right scope for a local operator surface.
 */
final class FileNonceLedger implements NonceLedger
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Wins the race or loses it, decided by the kernel rather than by this method.
     *
     * Returns false when the directory cannot be created: unable to remember means unable to
     * promise single use, and refusing beats granting on a promise it knows it is not keeping.
     */
    public function spend(string $nonce, int $ttlSeconds, int $now): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            // Cannot remember, so cannot promise single use. Refusing beats granting on a promise
            // this object knows it is not keeping.
            return false;
        }

        $this->forgetExpired($ttlSeconds, $now);

        // sha256 so an attacker-chosen nonce cannot become a path — and so the name is a fixed
        // length whatever was sent.
        $path = $this->directory . '/' . hash('sha256', $nonce);

        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false; // already spent
        }

        fwrite($handle, (string) $now);
        fclose($handle);

        return true;
    }

    /**
     * Drops entries too old to be replayable, so the directory stays the size of the window.
     *
     * Age is measured against the timestamp written *inside* each entry, not against its mtime.
     * The two are different clocks, and mixing them is how this swept the whole directory on every
     * call: with a caller whose `$now` ran ahead of the filesystem's, every record looked ancient,
     * every marker was deleted before the next one was written, and single-use silently became
     * unlimited-use. Eight processes racing for one authorization all won — which is precisely the
     * outcome this class exists to make impossible, arrived at through its cleanup path.
     */
    private function forgetExpired(int $ttlSeconds, int $now): void
    {
        $entries = @scandir($this->directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->directory . '/' . $entry;
            $written = @file_get_contents($path);

            // Unreadable or not a timestamp: leave it. Keeping a record we cannot date costs a
            // directory entry; deleting it costs the guarantee.
            if (!\is_string($written) || !ctype_digit(trim($written))) {
                continue;
            }

            if (($now - (int) trim($written)) > $ttlSeconds) {
                @unlink($path);
            }
        }
    }
}
