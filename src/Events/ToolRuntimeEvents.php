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

namespace Milpa\ToolRuntime\Events;

use Milpa\Events\VerificationGrantedEvent;
use Milpa\Events\VerificationRejectedEvent;
use Milpa\Events\VerificationRequestedEvent;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\Verification\HumanVerifier;

/**
 * Every event this package dispatches, declared by the emitters themselves (greenhouse decisions/0228).
 *
 * The constants are the names {@see ToolRegistry::call()} and {@see HumanVerifier} hand to
 * `dispatch()` — the dispatch sites and the declarations below read the SAME constant, so a
 * renamed event cannot drift between what fires and what is declared. Each emitter declares its
 * own names to any dispatcher implementing {@see \Milpa\Interfaces\Event\DeclaredEvents} the
 * moment it receives one ({@see self::forRegistry()} from the registry's constructor,
 * {@see self::forVerifier()} from the verifier's); a dispatcher that does not implement it is
 * asked nothing, and dispatching keeps working whether or not anything was declared.
 * {@see self::declarations()} is the package-wide list, the shape a catalogue prints.
 */
final class ToolRuntimeEvents
{
    /**
     * PRE, interceptable: fires inside {@see ToolRegistry::call()} once resolve, validate/clamp,
     * `PolicyGate::authorize()`, rate-limiting and the confirm-gate have ALL passed, with an
     * {@see \Milpa\Events\InterceptionSlot} under `slot` a listener may stop or short-circuit.
     */
    public const TOOL_EXECUTING = 'tool.executing';

    /**
     * POST, readonly: fires once a tool call finished — live, or served by a short-circuit
     * (`cacheServed: true`) — before the result returns to the caller.
     */
    public const TOOL_EXECUTED = 'tool.executed';

    /**
     * POST, readonly: fires when the tool's own callback threw, before the error result returns.
     */
    public const TOOL_FAILED = 'tool.failed';

    /**
     * Readonly: fires from {@see HumanVerifier::verify()} when a verification request is opened
     * for a human or agent to resolve out-of-band.
     */
    public const VERIFICATION_REQUESTED = 'verification.requested';

    /**
     * Readonly: fires from {@see HumanVerifier::grant()} when a human or agent grants a request.
     */
    public const VERIFICATION_GRANTED = 'verification.granted';

    /**
     * Readonly: fires from {@see HumanVerifier::reject()} when a human or agent rejects a request.
     */
    public const VERIFICATION_REJECTED = 'verification.rejected';

    /**
     * One declaration per event name this package dispatches: the registry's, then the verifier's.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [...self::forRegistry(), ...self::forVerifier()];
    }

    /**
     * The events {@see ToolRegistry} dispatches around every `call()`, in the order they fire.
     *
     * @return list<EventDeclaration>
     */
    public static function forRegistry(): array
    {
        return [
            new EventDeclaration(
                name: self::TOOL_EXECUTING,
                dispatchedBy: ToolRegistry::class,
                when: 'Right before a resolved, validated, authorized, rate-limited and confirmed tool call runs; a listener may veto or short-circuit it through the slot.',
                subjectKey: 'event',
                subjectType: ToolExecutingEvent::class,
                mutable: false,
                interceptable: true,
            ),
            new EventDeclaration(
                name: self::TOOL_EXECUTED,
                dispatchedBy: ToolRegistry::class,
                when: 'Once a tool call finished, live or served by a short-circuit, before its result returns to the caller.',
                subjectKey: 'event',
                subjectType: ToolExecutedEvent::class,
                mutable: false,
                interceptable: false,
            ),
            new EventDeclaration(
                name: self::TOOL_FAILED,
                dispatchedBy: ToolRegistry::class,
                when: 'When the tool\'s own callback threw, before the error result returns to the caller.',
                subjectKey: 'event',
                subjectType: ToolFailedEvent::class,
                mutable: false,
                interceptable: false,
            ),
        ];
    }

    /**
     * The events {@see HumanVerifier} dispatches over a verification's life, in the order they fire.
     *
     * @return list<EventDeclaration>
     */
    public static function forVerifier(): array
    {
        return [
            new EventDeclaration(
                name: self::VERIFICATION_REQUESTED,
                dispatchedBy: HumanVerifier::class,
                when: 'When a verification request is opened for a human or agent to resolve out-of-band; the verdict arrives later.',
                subjectKey: 'event',
                subjectType: VerificationRequestedEvent::class,
                mutable: false,
                interceptable: false,
            ),
            new EventDeclaration(
                name: self::VERIFICATION_GRANTED,
                dispatchedBy: HumanVerifier::class,
                when: 'When a human or agent grants a pending verification request.',
                subjectKey: 'event',
                subjectType: VerificationGrantedEvent::class,
                mutable: false,
                interceptable: false,
            ),
            new EventDeclaration(
                name: self::VERIFICATION_REJECTED,
                dispatchedBy: HumanVerifier::class,
                when: 'When a human or agent rejects a pending verification request.',
                subjectKey: 'event',
                subjectType: VerificationRejectedEvent::class,
                mutable: false,
                interceptable: false,
            ),
        ];
    }
}
