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

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A permission the session already recorded is consent too.
 *
 * The host asks —«the agent wants to run X, do you authorise it in this session?»—, a human answers
 * yes, and the grant is written to the session with its acta. This gate did not look there, so the
 * application asked a question whose answer could not work: measured end to end in greenhouse
 * evidence/0176, where the configuration was never written after an explicit yes, and the loop could
 * execute nothing requiring confirmation over the cli channel.
 *
 * The house was not being strict. It was asking something it could not honour.
 *
 * The second case is the control and it decides whether this fixed a seam or removed a gate: with no
 * grant, the same call must still be refused.
 */
#[CoversClass(PolicyGate::class)]
final class SessionPermissionIsConsentTest extends TestCase
{
    /** 1 · with the permission granted in this session, the call proceeds. */
    public function testAGrantedPermissionLetsTheCallThrough(): void
    {
        $decision = (new PolicyGate())->authorize(
            $this->cliCon(['config:set']),
            $this->herramienta('config:set'),
        );

        self::assertTrue($decision->allowed, (string) $decision->reason);
    }

    /**
     * 2 · THE CONTROL: with nothing granted, the same call is still refused.
     *
     * Without this, a gate that had simply stopped refusing would pass case one and read as fixed.
     */
    public function testWithNothingGrantedItIsStillRefused(): void
    {
        $decision = (new PolicyGate())->authorize(ToolContext::cli(), $this->herramienta('config:set'));

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('signature naming this call', (string) $decision->reason);
    }

    /**
     * 3 · a permission is for the tool it names, not a master key.
     *
     * Saying yes to one act is not saying yes to the next one, and a grant that widened to
     * everything would be worse than the silence it replaced.
     */
    public function testAGrantDoesNotOpenAnotherTool(): void
    {
        $decision = (new PolicyGate())->authorize(
            $this->cliCon(['config:set']),
            $this->herramienta('plugins:register'),
        );

        self::assertFalse($decision->allowed);
    }

    /** 4 · an empty grant list is not a grant. */
    public function testAnEmptyGrantIsNotAGrant(): void
    {
        self::assertFalse((new PolicyGate())->authorize($this->cliCon([]), $this->herramienta('config:set'))->allowed);
    }

    /** 5 · what never asked for confirmation keeps running unattended, grant or no grant. */
    public function testUnattendedWorkIsUntouched(): void
    {
        $sinConfirmar = new ToolDefinition(
            name: 'cache.warm',
            description: 'Warms the cache',
            inputSchema: [],
            callback: static fn (): null => null,
            scopes: [],
            mutating: true,
            requiresConfirmation: false,
        );

        self::assertTrue((new PolicyGate())->authorize(ToolContext::cli(), $sinConfirmar)->allowed);
    }

    /** @param list<string> $concedidas */
    private function cliCon(array $concedidas): ToolContext
    {
        $cli = ToolContext::cli();

        return new ToolContext(
            principal: $cli->principal,
            channel: $cli->channel,
            scopes: $cli->scopes,
            ip: $cli->ip,
            userAgent: $cli->userAgent,
            extra: ['session.granted' => $concedidas],
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
