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

use Milpa\ToolRuntime\Identity\LocalOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Who the audit log says acted, when the OS is the only authority available.
 *
 * Every case here is one the suite could never reach by running as whoever owns the machine: root
 * with sudo behind it, root without, an ordinary account, and a host with no ext-posix at all. A
 * resolver that is only correct for its author's uid is not a resolver, so the facts go in through
 * a seam and the assertions are about the distinction that matters — what the kernel established
 * against what the environment merely said.
 */
#[CoversClass(LocalOperator::class)]
final class LocalOperatorTest extends TestCase
{
    public function test_an_ordinary_account_is_named_by_the_kernel(): void
    {
        $operator = LocalOperator::from(uid: 1000, userName: 'rod', env: []);

        self::assertSame('rod', $operator->principal());
        self::assertSame('kernel', $operator->attributes()['os.user_source']);
        self::assertSame(1000, $operator->attributes()['os.uid']);
    }

    public function test_root_alone_is_recorded_as_root(): void
    {
        // Nothing claims to be behind it, so nothing is invented.
        $operator = LocalOperator::from(uid: 0, userName: 'root', env: []);

        self::assertSame('root', $operator->principal());
    }

    public function test_root_reached_through_sudo_keeps_both_the_account_and_the_elevation(): void
    {
        // The failure this prevents: three admins sudo to root and the log shows one actor.
        $operator = LocalOperator::from(uid: 0, userName: 'root', env: ['SUDO_USER' => 'rod']);

        self::assertSame('root(sudo:rod)', $operator->principal());
    }

    public function test_the_sudo_claim_never_becomes_the_identity(): void
    {
        // This is the whole point. The action carried root's authority; the environment says a
        // human called rod is behind it. Printing 'rod' would be a wrong that gets believed.
        $operator = LocalOperator::from(uid: 0, userName: 'root', env: ['SUDO_USER' => 'rod']);

        self::assertStringContainsString('root', $operator->principal());
        self::assertSame('rod', $operator->attributes()['os.sudo_user_claim']);
        self::assertArrayNotHasKey('os.sudo_user', $operator->attributes(), 'The key is named _claim so nothing downstream reads it as established.');
    }

    public function test_a_sudo_claim_without_root_is_recorded_and_ignored_for_the_name(): void
    {
        // An unprivileged process can export SUDO_USER to anything. With a non-root uid the kernel
        // already named the actor, so the claim changes nothing about who acted.
        $operator = LocalOperator::from(uid: 1000, userName: 'rod', env: ['SUDO_USER' => 'someone-else']);

        self::assertSame('rod', $operator->principal());
        self::assertSame('someone-else', $operator->attributes()['os.sudo_user_claim']);
    }

    public function test_without_ext_posix_the_name_is_marked_unverified(): void
    {
        // ext-posix is not in this package's requires. The fallback has to say so out loud rather
        // than pass an environment variable off as the kernel's answer.
        $operator = LocalOperator::from(uid: null, userName: null, env: ['USER' => 'rod']);

        self::assertSame('unverified:rod', $operator->principal());
        self::assertSame('environment', $operator->attributes()['os.user_source']);
        self::assertArrayNotHasKey('os.uid', $operator->attributes());
    }

    public function test_with_neither_a_uid_nor_a_name_it_still_says_something_honest(): void
    {
        $operator = LocalOperator::from(uid: null, userName: null, env: []);

        self::assertSame('unverified', $operator->principal());
    }

    public function test_a_uid_with_no_account_entry_falls_back_to_the_number(): void
    {
        // A uid with no passwd entry is real — containers do it constantly. The number is still
        // the kernel's answer, so it is still worth more than a blank.
        $operator = LocalOperator::from(uid: 1001, userName: null, env: []);

        self::assertSame('uid:1001', $operator->principal());
        self::assertSame('kernel', $operator->attributes()['os.user_source']);
    }

    public function test_the_ssh_origin_is_the_client_address_and_nothing_else(): void
    {
        $operator = LocalOperator::from(
            uid: 1000,
            userName: 'rod',
            env: ['SSH_CONNECTION' => '203.0.113.4 54321 10.0.0.2 22'],
        );

        self::assertSame('203.0.113.4', $operator->originIp());
        self::assertSame('203.0.113.4 54321 10.0.0.2 22', $operator->attributes()['ssh.connection_claim']);
    }

    public function test_a_local_shell_has_no_origin_address(): void
    {
        self::assertNull(LocalOperator::from(uid: 1000, userName: 'rod', env: [])->originIp());
    }

    public function test_a_malformed_ssh_connection_does_not_invent_an_address(): void
    {
        $operator = LocalOperator::from(uid: 1000, userName: 'rod', env: ['SSH_CONNECTION' => '   ']);

        self::assertNull($operator->originIp(), 'Whitespace is not an address.');
    }

    public function test_empty_environment_values_are_absent_rather_than_empty(): void
    {
        // '' and "not set" mean the same thing here, and a key holding '' in an audit line reads
        // like a fact that happens to be blank.
        $operator = LocalOperator::from(
            uid: 1000,
            userName: 'rod',
            env: ['SUDO_USER' => '', 'SSH_CONNECTION' => ''],
        );

        self::assertArrayNotHasKey('os.sudo_user_claim', $operator->attributes());
        self::assertArrayNotHasKey('ssh.connection_claim', $operator->attributes());
    }

    public function test_the_session_and_host_are_carried_so_two_shells_are_distinguishable(): void
    {
        $operator = LocalOperator::from(
            uid: 1000,
            userName: 'rod',
            env: [],
            tty: '/dev/pts/3',
            host: 'milpa-prod-1',
        );

        self::assertSame('/dev/pts/3', $operator->attributes()['os.tty']);
        self::assertSame('milpa-prod-1', $operator->attributes()['os.host']);
    }

    public function test_asking_the_running_system_produces_an_attributable_actor(): void
    {
        // The one case that touches the real machine. It cannot assert a name — the runner's uid is
        // not ours to choose — so it asserts the property the audit log depends on: something was
        // resolved, and it is not the placeholder this replaced.
        $operator = LocalOperator::fromEnvironment();

        self::assertNotSame('', $operator->principal());
        self::assertNotSame('cli', $operator->principal());
        self::assertContains($operator->attributes()['os.user_source'], ['kernel', 'environment']);
    }
}
