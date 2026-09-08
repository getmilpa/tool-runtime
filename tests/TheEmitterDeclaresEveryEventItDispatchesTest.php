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

namespace Milpa\ToolRuntime\Tests;

use Milpa\Eventing\EventDispatcher;
use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Events\ToolRuntimeEvents;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\Verification\HumanVerifier;
use Milpa\ValueObjects\Verification\VerificationContext;
use Milpa\ValueObjects\Verification\VerificationRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Falsifier for greenhouse decisions/0228: each emitter declares every event it dispatches, to
 * the dispatcher, from the same constants the dispatch sites use.
 *
 * The package's two emitters — {@see ToolRegistry} (one call that succeeds, one whose callback
 * throws) and {@see HumanVerifier} (verify, grant, reject) — are driven through their REAL code
 * paths with a SPY that implements both {@see MilpaEventDispatcherInterface} and
 * {@see DeclaredEvents} and records what was declared and what was dispatched. Then: nothing
 * dispatched went undeclared, the declared set is exactly the list pinned here (a deleted or
 * renamed declaration goes red), each declaration's subject matches the dispatched payload, and
 * — the control — a dispatcher that cannot hold declarations still runs both paths unharmed.
 */
final class TheEmitterDeclaresEveryEventItDispatchesTest extends TestCase
{
    /**
     * The exact names {@see ToolRegistry} dispatches. Hardcoded on purpose: the test must not read
     * the expected list from the class under test.
     */
    private const EXPECTED_REGISTRY_NAMES = ['tool.executing', 'tool.executed', 'tool.failed'];

    /**
     * The exact names {@see HumanVerifier} dispatches, hardcoded for the same reason.
     */
    private const EXPECTED_VERIFIER_NAMES = ['verification.requested', 'verification.granted', 'verification.rejected'];

    /**
     * Two real calls through {@see ToolRegistry::call()} with the spy — one tool returns, one
     * throws — so all three `tool.*` names dispatch: every dispatched name was declared, the
     * declared set is exactly {@see self::EXPECTED_REGISTRY_NAMES}, and each declaration's
     * `subjectKey`, `subjectType`, `dispatchedBy` and `interceptable` match the payload really seen.
     */
    public function testTheRegistryDeclaresEveryEventItDispatchesWithTheSubjectItReallyCarries(): void
    {
        $spy = $this->spy();
        $registry = $this->registryWithTwoTools($spy);

        $ok = $registry->call('echo', ['text' => 'hi'], ToolContext::cli());
        $failed = $registry->call('explode', [], ToolContext::cli());

        $this->assertTrue($ok->success, 'the live path must have run');
        $this->assertFalse($failed->success, 'the throwing path must have run');

        $this->assertDeclaredExactly(self::EXPECTED_REGISTRY_NAMES, $spy, ToolRegistry::class);
    }

    /**
     * The verifier's three moments with the spy — `verify()`, `grant()`, `reject()` — so all three
     * `verification.*` names dispatch, then the same four checks as for the registry.
     */
    public function testTheVerifierDeclaresEveryEventItDispatchesWithTheSubjectItReallyCarries(): void
    {
        $spy = $this->spy();
        $verifier = new HumanVerifier($spy);
        $request = new VerificationRequest(subject: 'gate:report.publish', requestedBy: 'user:1');

        $pending = $verifier->verify($request, new VerificationContext(principal: 'user:1'));
        $granted = $verifier->grant($request, 'reviewer:1', 'looks right');
        $rejected = $verifier->reject($request, 'reviewer:2', 'not yet');

        $this->assertTrue($pending->isPending(), 'verify() must have run');
        $this->assertTrue($granted->isSatisfied(), 'grant() must have run');
        $this->assertFalse($rejected->isSatisfied(), 'reject() must have run');

        $this->assertDeclaredExactly(self::EXPECTED_VERIFIER_NAMES, $spy, HumanVerifier::class);
    }

    /**
     * The package as a whole: both emitters wired to ONE dispatcher declare exactly the six
     * names, the registry's first, and that is what {@see ToolRuntimeEvents::declarations()}
     * prints — built from the same constants the dispatch sites use, so a rename moves both and
     * a retyped string cannot.
     */
    public function testBothEmittersOnOneDispatcherDeclareExactlyThePackageList(): void
    {
        $spy = $this->spy();
        new ToolRegistry(new NullLogger(), $spy);
        new HumanVerifier($spy);

        $expected = [...self::EXPECTED_REGISTRY_NAMES, ...self::EXPECTED_VERIFIER_NAMES];
        $this->assertSame($expected, $this->names($spy->declared()));
        $this->assertSame($expected, $this->names(ToolRuntimeEvents::declarations()));
        $this->assertSame([
            ToolRuntimeEvents::TOOL_EXECUTING,
            ToolRuntimeEvents::TOOL_EXECUTED,
            ToolRuntimeEvents::TOOL_FAILED,
            ToolRuntimeEvents::VERIFICATION_REQUESTED,
            ToolRuntimeEvents::VERIFICATION_GRANTED,
            ToolRuntimeEvents::VERIFICATION_REJECTED,
        ], $this->names(ToolRuntimeEvents::declarations()));
    }

    /**
     * The reference dispatcher of the family (`milpa/events`) holds declarations: after wiring
     * both emitters it answers the six names from `declared()`, and nothing was dispatched yet —
     * a declaration describes, it never dispatches.
     */
    public function testTheReferenceDispatcherHoldsTheDeclarationsWithoutDispatchingAnything(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        $this->assertInstanceOf(DeclaredEvents::class, $dispatcher);

        new ToolRegistry(new NullLogger(), $dispatcher);
        new HumanVerifier($dispatcher);

        $this->assertSame([...self::EXPECTED_REGISTRY_NAMES, ...self::EXPECTED_VERIFIER_NAMES], $this->names($dispatcher->declared()));
        $this->assertSame([], $dispatcher->dispatched());
    }

    /**
     * CONTROL: a dispatcher that does NOT implement {@see DeclaredEvents} runs both code paths
     * without error — nothing is declared to it, and all six names still dispatch.
     */
    public function testADispatcherThatCannotHoldDeclarationsIsAskedNothingAndStillReceivesEveryDispatch(): void
    {
        $plain = new class () implements MilpaEventDispatcherInterface {
            /** @var list<string> */
            public array $dispatched = [];

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->dispatched[] = $eventName;
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };

        $registry = $this->registryWithTwoTools($plain);
        $this->assertTrue($registry->call('echo', ['text' => 'hi'], ToolContext::cli())->success);
        $this->assertFalse($registry->call('explode', [], ToolContext::cli())->success);

        $verifier = new HumanVerifier($plain);
        $request = new VerificationRequest(subject: 'gate:report.publish');
        $verifier->verify($request, new VerificationContext());
        $verifier->grant($request, 'reviewer:1');
        $verifier->reject($request, 'reviewer:2', 'no');

        $this->assertSame([
            'tool.executing', 'tool.executed',
            'tool.executing', 'tool.failed',
            'verification.requested', 'verification.granted', 'verification.rejected',
        ], $plain->dispatched);
    }

    /**
     * The four checks of the falsifier against one emitter's spy: (a) no undeclared dispatch and
     * nothing declared that this path did not dispatch, (b) the declared set is exactly the pinned
     * list in declaration order, (c) each declaration describes the payload really dispatched
     * under its name — key, type, emitter, readonly subject, slot iff interceptable.
     *
     * @param list<string>                                                                                             $expectedNames
     * @param MilpaEventDispatcherInterface&DeclaredEvents&object{payloads: array<string, list<array<string, mixed>>>} $spy
     * @param class-string                                                                                             $emitter
     */
    private function assertDeclaredExactly(array $expectedNames, MilpaEventDispatcherInterface&DeclaredEvents $spy, string $emitter): void
    {
        $declaredNames = $this->names($spy->declared());

        // (b) the declared set is exactly the pinned list, in declaration order.
        $this->assertSame($expectedNames, $declaredNames);

        // (a) no undeclared dispatch — and the path dispatched something, so (a) is not vacuous.
        $this->assertNotEmpty($spy->dispatched(), 'the code path must dispatch, or the check proves nothing');
        $this->assertSame([], array_values(array_diff($spy->dispatched(), $declaredNames)), 'dispatched without a declaration');
        $this->assertSame([], array_values(array_diff($declaredNames, $spy->dispatched())), 'declared but never dispatched on this path');

        // (c) each declaration describes the payload that was really dispatched under that name.
        foreach ($spy->declared() as $declaration) {
            $payload = $spy->payloads[$declaration->name][0];
            $this->assertArrayHasKey($declaration->subjectKey, $payload, $declaration->name);
            $this->assertNotNull($declaration->subjectType, $declaration->name);
            $this->assertInstanceOf($declaration->subjectType, $payload[$declaration->subjectKey], $declaration->name);
            $this->assertSame($emitter, $declaration->dispatchedBy, $declaration->name);
            $this->assertFalse($declaration->mutable, $declaration->name . ' carries a readonly VO');
            $this->assertSame(
                $declaration->interceptable,
                ($payload['slot'] ?? null) instanceof InterceptionSlot,
                $declaration->name . ': interceptable must mean a slot travels in the payload',
            );
        }
    }

    /**
     * A registry with a tool that returns and a tool whose callback throws, so one call each
     * covers `tool.executing` + `tool.executed` and `tool.executing` + `tool.failed`.
     */
    private function registryWithTwoTools(MilpaEventDispatcherInterface $dispatcher): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger(), $dispatcher);
        $registry->register(
            'echo',
            'Returns what it was given',
            ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
            static fn (array $args): array => ['text' => $args['text']],
        );
        $registry->register(
            'explode',
            'Throws from its own callback',
            ['type' => 'object', 'properties' => []],
            static function (): never {
                throw new \RuntimeException('boom');
            },
        );

        return $registry;
    }

    /**
     * @param list<EventDeclaration> $declarations
     *
     * @return list<string>
     */
    private function names(array $declarations): array
    {
        return array_map(static fn (EventDeclaration $d): string => $d->name, $declarations);
    }

    /**
     * A spy that is both a dispatcher and a holder of declarations: records every declared name
     * (first declaration of a name wins, as the contract says) and every dispatched payload.
     *
     * @return MilpaEventDispatcherInterface&DeclaredEvents&object{payloads: array<string, list<array<string, mixed>>>}
     */
    private function spy(): MilpaEventDispatcherInterface&DeclaredEvents
    {
        return new class () implements MilpaEventDispatcherInterface, DeclaredEvents {
            /** @var array<string, EventDeclaration> */
            private array $declarations = [];

            /** @var array<string, list<array<string, mixed>>> */
            public array $payloads = [];

            public function declare(EventDeclaration ...$events): void
            {
                foreach ($events as $event) {
                    $this->declarations[$event->name] ??= $event;
                }
            }

            public function declared(): array
            {
                return array_values($this->declarations);
            }

            public function dispatched(): array
            {
                return array_keys($this->payloads);
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->payloads[$eventName][] = $payload;
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };
    }
}
