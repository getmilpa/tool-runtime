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

use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate decides on the fact, and never learns to spell.
 *
 * greenhouse decisions/0030: consent happens once, and everything else should be transport, evidence
 * or projection of that same act. This gate used to compare a tool's name against a list of strings,
 * so a permission written `config:set` did not match a tool named `config_set` and a human's yes was
 * worth nothing (evidence/0176).
 *
 * The third case is the one that separates identity from UI: the same grant, the same act, three
 * spellings, one verdict.
 */
#[CoversClass(PolicyGate::class)]
final class TheGateDecidesOnTheFactTest extends TestCase
{
    /** 1 · a grant covering this act lets the call through. */
    public function testAGrantForThisActLetsItThrough(): void
    {
        $decision = (new PolicyGate())->authorize(
            $this->cliCon($this->grant('config.set')),
            $this->herramienta('config_set'),
        );

        self::assertTrue($decision->allowed, (string) $decision->reason);
    }

    /**
     * 2 · THE CONTROL: with no grant at all, the same call is still refused.
     *
     * Without this, a gate that had stopped refusing would pass every other case and read as fixed.
     */
    public function testWithNoGrantItIsStillRefused(): void
    {
        $decision = (new PolicyGate())->authorize(ToolContext::cli(), $this->herramienta('config_set'));

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('signature naming this call', (string) $decision->reason);
    }

    /** 3 · THE SAME grant, the same act, three spellings, one verdict. */
    public function testSpellingDoesNotChangeTheVerdict(): void
    {
        $grant = $this->grant('config.set');
        $gate = new PolicyGate();

        foreach (['config.set', 'config:set', 'config_set'] as $comoSeLlame) {
            self::assertTrue(
                $gate->authorize($this->cliCon($grant), $this->herramienta($comoSeLlame))->allowed,
                "la ortografía «{$comoSeLlame}» cambió el veredicto",
            );
        }
    }

    /** 4 · a grant for one act does not open another. */
    public function testAGrantDoesNotOpenAnotherAct(): void
    {
        $decision = (new PolicyGate())->authorize(
            $this->cliCon($this->grant('config.set')),
            $this->herramienta('plugins_register'),
        );

        self::assertFalse($decision->allowed);
    }

    /** 5 · a grant naming its arguments does not cover a call with different ones. */
    public function testArgumentsAreSubstantive(): void
    {
        $grant = new ConsentGrant(
            operation: new OperationId('config.set'),
            principal: 'cli:rod@cm4070',
            session: 'ses-A',
            grantedAt: new \DateTimeImmutable('2026-08-13 10:00:00'),
            provenance: 'session.question_answered',
            arguments: ['key' => 'agent.treeBudget', 'value' => 7],
        );

        $gate = new PolicyGate();
        $ok = $gate->authorize($this->cliCon($grant, ['key' => 'agent.treeBudget', 'value' => 7]), $this->herramienta('config_set'));
        $no = $gate->authorize($this->cliCon($grant, ['key' => 'agent.treeBudget', 'value' => 999]), $this->herramienta('config_set'));

        self::assertTrue($ok->allowed, (string) $ok->reason);
        self::assertFalse($no->allowed);
    }

    /**
     * 6 · a session can hold more than one yes, and neither one erases the other.
     *
     * Accepting a single grant forced the host to overwrite the context per permission, so with two
     * authorisations one was lost in silence — the quiet kind of loss this house refuses.
     */
    public function testASessionCanHoldMoreThanOneYes(): void
    {
        $cli = ToolContext::cli();
        $ctx = new ToolContext(
            principal: $cli->principal,
            channel: $cli->channel,
            scopes: $cli->scopes,
            extra: ['consent.grants' => [$this->grant('config.set'), $this->grant('plugins.register')]],
        );

        $gate = new PolicyGate();

        self::assertTrue($gate->authorize($ctx, $this->herramienta('config_set'))->allowed);
        self::assertTrue($gate->authorize($ctx, $this->herramienta('plugins_register'))->allowed);
        self::assertFalse($gate->authorize($ctx, $this->herramienta('foundation_found'))->allowed);
    }

    /** @param array<string, mixed> $argumentos */
    private function cliCon(ConsentGrant $grant, array $argumentos = []): ToolContext
    {
        $cli = ToolContext::cli();

        return new ToolContext(
            principal: $cli->principal,
            channel: $cli->channel,
            scopes: $cli->scopes,
            ip: $cli->ip,
            userAgent: $cli->userAgent,
            extra: ['consent.grant' => $grant, 'consent.arguments' => $argumentos],
        );
    }

    private function grant(string $operacion): ConsentGrant
    {
        return new ConsentGrant(
            operation: new OperationId($operacion),
            principal: 'cli:rod@cm4070',
            session: 'ses-A',
            grantedAt: new \DateTimeImmutable('2026-08-13 10:00:00'),
            provenance: 'session.question_answered',
        );
    }

    private function herramienta(string $nombre): ToolDefinition
    {
        return new ToolDefinition(
            name: $nombre,
            description: 'A tool that asks before it acts',
            inputSchema: [],
            callback: static fn (): null => null,
            scopes: [],
            mutating: true,
            requiresConfirmation: true,
        );
    }
}
