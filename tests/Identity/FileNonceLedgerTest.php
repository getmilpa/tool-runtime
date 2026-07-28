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

use Milpa\ToolRuntime\Identity\FileNonceLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Spending an authorization exactly once, including when two processes try at the same instant.
 */
#[CoversClass(FileNonceLedger::class)]
final class FileNonceLedgerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-nonce-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach ((array) glob($this->dir . '/*') as $file) {
            if (\is_string($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function test_the_first_use_succeeds_and_the_second_does_not(): void
    {
        $ledger = new FileNonceLedger($this->dir);

        self::assertTrue($ledger->spend('abc', 120, 1_800_000_000));
        self::assertFalse($ledger->spend('abc', 120, 1_800_000_000));
    }

    public function test_different_authorizations_do_not_collide(): void
    {
        $ledger = new FileNonceLedger($this->dir);

        self::assertTrue($ledger->spend('abc', 120, 1_800_000_000));
        self::assertTrue($ledger->spend('def', 120, 1_800_000_000));
    }

    public function test_it_creates_its_directory_rather_than_failing_on_first_use(): void
    {
        self::assertDirectoryDoesNotExist($this->dir);

        self::assertTrue((new FileNonceLedger($this->dir))->spend('abc', 120, 1_800_000_000));

        self::assertDirectoryExists($this->dir);
    }

    public function test_a_nonce_is_never_a_path(): void
    {
        // The nonce comes from outside. Used as a filename it would be a traversal; hashed, its
        // shape is fixed whatever arrives.
        $ledger = new FileNonceLedger($this->dir);

        self::assertTrue($ledger->spend('../../etc/passwd', 120, 1_800_000_000));

        $written = (array) glob($this->dir . '/*');
        self::assertCount(1, $written);
        self::assertMatchesRegularExpression('/\/[0-9a-f]{64}$/', (string) $written[0]);
    }

    public function test_entries_past_the_window_are_forgotten(): void
    {
        $ledger = new FileNonceLedger($this->dir);
        $ledger->spend('abc', 120, 1_800_000_000);
        self::assertCount(1, (array) glob($this->dir . '/*'));

        // Well past the window: the authorizer would refuse this on age anyway, so keeping the
        // record is storing proof of something already impossible.
        $ledger->spend('def', 120, 1_800_000_000 + 100_000);

        $remaining = array_map('basename', (array) glob($this->dir . '/*'));
        self::assertCount(1, $remaining, 'The stale entry is dropped when a later use sweeps.');
        self::assertNotContains(hash('sha256', 'abc'), $remaining);
    }

    public function test_unable_to_remember_means_unable_to_grant(): void
    {
        // The directory cannot be created because its parent is a file. A ledger that cannot
        // record has no way to keep single use, so it refuses rather than granting on a promise
        // it knows it is not keeping.
        $file = sys_get_temp_dir() . '/milpa-not-a-dir-' . bin2hex(random_bytes(4));
        file_put_contents($file, 'x');

        try {
            self::assertFalse((new FileNonceLedger($file . '/inside'))->spend('abc', 120, 1_800_000_000));
        } finally {
            @unlink($file);
        }
    }

    public function test_an_entry_it_cannot_date_is_kept_rather_than_swept(): void
    {
        // Deleting a record whose age is unknown would silently free the nonce for reuse. Keeping
        // it costs one directory entry; dropping it costs the guarantee.
        $ledger = new FileNonceLedger($this->dir);
        $ledger->spend('abc', 120, 1_800_000_000);
        file_put_contents($this->dir . '/' . str_repeat('a', 64), 'no es una marca de tiempo');

        $ledger->spend('def', 120, 1_800_000_000 + 100_000);

        self::assertFileExists($this->dir . '/' . str_repeat('a', 64));
    }

    public function test_two_processes_racing_for_one_authorization_produce_exactly_one_winner(): void
    {
        // The case the ledger exists for, and the one a file_exists() check passes in every test
        // and loses in production. Forked children each try to spend the same nonce; the
        // filesystem settles it, and exactly one may win.
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required to race real processes.');
        }

        $ledger = new FileNonceLedger($this->dir);
        $ledger->spend('warmup', 120, 1_800_000_000); // create the directory before forking
        $resultFile = $this->dir . '/../race-' . bin2hex(random_bytes(4));

        $children = [];
        for ($i = 0; $i < 8; ++$i) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $won = (new FileNonceLedger($this->dir))->spend('contested', 120, 1_800_000_000);
                file_put_contents($resultFile, $won ? "W\n" : "L\n", \FILE_APPEND | \LOCK_EX);
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $outcomes = array_filter(explode("\n", (string) @file_get_contents($resultFile)));
        @unlink($resultFile);

        self::assertCount(8, $outcomes, 'Every child reported.');
        self::assertSame(1, \count(array_filter($outcomes, static fn (string $o): bool => $o === 'W')));
    }
}
