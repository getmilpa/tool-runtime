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
 * Who is at the local shell, as far as the operating system can actually say.
 *
 * A surface that delegates authentication to the OS still has to record *which* operator acted.
 * Without this, every local invocation is attributed to the literal string `cli` — so the most
 * privileged actions in the system end up with the worst attribution in its audit log, and two
 * administrators on one host are indistinguishable after the fact.
 *
 * The whole design turns on one distinction, because the facts on offer are not equally true:
 *
 * - **The kernel's answer** — the effective uid, and the account name it maps to — cannot be forged
 *   by the process asking. It is the only thing here worth calling identity.
 * - **The environment's claim** — `SUDO_USER`, `SSH_CONNECTION` — is a variable. `sudo` sets
 *   `SUDO_USER` honestly under `env_reset`, but a process already running as root can export
 *   anything it likes before invoking this. Recorded, always; believed, never.
 *
 * So the two travel in different fields and are labelled as what they are. An audit line that reads
 * `rod did this` when the system can only prove `uid 0 did this, and the environment said rod` is a
 * more expensive kind of wrong than no attribution at all: it is trusted.
 */
final class LocalOperator
{
    /**
     * @param int|null    $uid       effective uid from the kernel, or null when ext-posix is absent
     * @param string|null $userName  account name for that uid — kernel-derived when $uid is not null
     * @param string|null $sudoUser  the `SUDO_USER` claim, environment-derived, never authoritative
     * @param string|null $sshClient the `SSH_CONNECTION` value, if this shell arrived over ssh
     * @param string|null $tty       controlling terminal, which separates concurrent sessions
     * @param string|null $host      hostname, so a principal means something across machines
     */
    private function __construct(
        public readonly ?int $uid,
        public readonly ?string $userName,
        public readonly ?string $sudoUser,
        public readonly ?string $sshClient,
        public readonly ?string $tty,
        public readonly ?string $host,
    ) {
    }

    /**
     * Ask the running system.
     *
     * `posix_geteuid()` is deliberately preferred over `getenv('USER')`: the environment variable
     * says who logged in, the effective uid says who is acting, and after `sudo` those differ —
     * which is exactly the case this exists to get right.
     */
    public static function fromEnvironment(): self
    {
        $uid = null;
        $userName = null;

        // ext-posix is not in this package's requires, and pretending otherwise would make the
        // fallback path the untested one on the machines that need it most.
        if (\function_exists('posix_geteuid')) {
            $uid = posix_geteuid();
            if (\function_exists('posix_getpwuid')) {
                // Returns false for a uid with no passwd entry — routine in containers, and the
                // reason this is not simply dereferenced.
                $entry = posix_getpwuid($uid);
                $userName = \is_array($entry) ? $entry['name'] : null;
            }
        }

        $tty = null;
        if (\function_exists('posix_ttyname') && \defined('STDIN')) {
            $name = @posix_ttyname(\STDIN);
            $tty = \is_string($name) ? $name : null;
        }

        return self::from(
            uid: $uid,
            userName: $userName,
            env: [
                'SUDO_USER' => self::env('SUDO_USER'),
                'SSH_CONNECTION' => self::env('SSH_CONNECTION'),
                'USER' => self::env('USER'),
            ],
            tty: $tty,
            host: \gethostname() ?: null,
        );
    }

    /**
     * Build from supplied facts — the seam every test uses.
     *
     * Root-with-sudo, root-alone, an ordinary user and a host without ext-posix are four different
     * outputs, and none of them can be exercised by running the suite as whoever happens to own the
     * CI runner. A resolver that only reports correctly for the user who wrote it is not a resolver.
     *
     * @param array<string, string|null> $env
     */
    public static function from(?int $uid, ?string $userName, array $env, ?string $tty = null, ?string $host = null): self
    {
        // With no kernel answer, the environment's idea of the user is all there is. It is carried
        // so the line is not empty, and marked so it is never read as proof.
        if ($uid === null && $userName === null) {
            $claimed = $env['USER'] ?? null;
            $userName = $claimed !== null && $claimed !== '' ? $claimed : null;
        }

        return new self(
            uid: $uid,
            userName: $userName,
            sudoUser: self::nonEmpty($env['SUDO_USER'] ?? null),
            sshClient: self::nonEmpty($env['SSH_CONNECTION'] ?? null),
            tty: self::nonEmpty($tty),
            host: self::nonEmpty($host),
        );
    }

    /**
     * The string that lands in the audit log.
     *
     * Four shapes, each saying exactly how much is known:
     *
     * - `rod` — the kernel resolved a non-root account.
     * - `root` — root, with nothing claiming to be behind it.
     * - `root(sudo:rod)` — root, reached from an account the environment names. The parenthesis is
     *   not decoration: it keeps the elevation visible instead of collapsing every administrator
     *   into one indistinguishable `root`, while still refusing to print `rod` for an action taken
     *   with root's authority.
     * - `unverified:rod` / `unverified` — no kernel answer available. Whatever follows the prefix
     *   came from the environment and is worth what the environment is worth.
     */
    public function principal(): string
    {
        if ($this->uid === null) {
            return $this->userName !== null ? 'unverified:' . $this->userName : 'unverified';
        }

        $name = $this->userName ?? ('uid:' . $this->uid);

        if ($this->uid === 0 && $this->sudoUser !== null) {
            return $name . '(sudo:' . $this->sudoUser . ')';
        }

        return $name;
    }

    /**
     * The raw facts, each tagged with how far it can be trusted.
     *
     * `os.user_source` is the field that keeps this honest: a reader who only sees `os.user` cannot
     * tell whether the kernel said it or an environment variable did, and those are different
     * claims that look identical once written down.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $facts = [
            'os.user_source' => $this->uid !== null ? 'kernel' : 'environment',
        ];

        if ($this->uid !== null) {
            $facts['os.uid'] = $this->uid;
        }
        if ($this->userName !== null) {
            $facts['os.user'] = $this->userName;
        }
        if ($this->sudoUser !== null) {
            // Named `_claim` so nothing downstream can read it as established.
            $facts['os.sudo_user_claim'] = $this->sudoUser;
        }
        if ($this->tty !== null) {
            $facts['os.tty'] = $this->tty;
        }
        if ($this->host !== null) {
            $facts['os.host'] = $this->host;
        }
        if ($this->sshClient !== null) {
            $facts['ssh.connection_claim'] = $this->sshClient;
        }

        return $facts;
    }

    /**
     * The address this shell came from, when it came over ssh.
     *
     * `SSH_CONNECTION` is `<client ip> <client port> <server ip> <server port>`; the first field is
     * the one worth recording. Environment-derived like the rest, so it lands in the context's `ip`
     * for correlation and carries no authority on its own.
     */
    public function originIp(): ?string
    {
        if ($this->sshClient === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($this->sshClient)) ?: [];

        return isset($parts[0]) && $parts[0] !== '' ? $parts[0] : null;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) ? $value : null;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value !== null && $value !== '' ? $value : null;
    }
}
