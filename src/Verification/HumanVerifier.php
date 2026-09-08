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

namespace Milpa\ToolRuntime\Verification;

use Milpa\Events\VerificationGrantedEvent;
use Milpa\Events\VerificationRejectedEvent;
use Milpa\Events\VerificationRequestedEvent;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Verification\VerifierInterface;
use Milpa\ToolRuntime\Events\ToolRuntimeEvents;
use Milpa\ValueObjects\Verification\VerificationContext;
use Milpa\ValueObjects\Verification\VerificationRequest;
use Milpa\ValueObjects\Verification\VerificationResult;

/**
 * The human/agent-out-of-band verifier (D8 / T089): the reference implementation of the core
 * {@see VerifierInterface} whose verdict is supplied by a human or agent out-of-band.
 *
 * Exposed as the `request_verification` / `resolve_verification` tools by
 * {@see VerificationTool} (split from a single combined tool in tool-runtime 0.3 — see that
 * class's docblock for why). `verify()` cannot decide synchronously, so it returns a
 * PENDING result and announces `verification.requested`; the verdict arrives later via
 * {@see grant()} / {@see reject()}, which emit `verification.granted` /
 * `verification.rejected`. No Doctrine, opaque principals.
 */
final class HumanVerifier implements VerifierInterface
{
    /** Identifies this verifier implementation in {@see \Milpa\ValueObjects\Verification\VerificationResult::$verifier}. */
    public const NAME = 'human_verifier';

    /**
     * @param MilpaEventDispatcherInterface|null $dispatcher Optional event dispatcher; `null` announces nothing. The
     *                                                       three `verification.*` events are declared to it here, where
     *                                                       it enters the package (greenhouse decisions/0228), when it
     *                                                       implements {@see DeclaredEvents} — otherwise it is asked nothing.
     */
    public function __construct(private readonly ?MilpaEventDispatcherInterface $dispatcher = null)
    {
        if ($dispatcher instanceof DeclaredEvents) {
            $dispatcher->declare(...ToolRuntimeEvents::forVerifier());
        }
    }

    /**
     * Open a verification request and announce it out-of-band; never resolves synchronously.
     *
     * Always returns a PENDING result and dispatches `verification.requested` — the actual
     * verdict arrives later via {@see grant()} or {@see reject()}.
     */
    public function verify(VerificationRequest $request, VerificationContext $context): VerificationResult
    {
        $this->dispatcher?->dispatch(ToolRuntimeEvents::VERIFICATION_REQUESTED, ['event' => new VerificationRequestedEvent($request)]);

        return VerificationResult::pending(verifier: self::NAME);
    }

    /**
     * Human/agent grants the verification.
     */
    public function grant(VerificationRequest $request, string $principal, ?string $reason = null): VerificationResult
    {
        $result = VerificationResult::pass(
            verifier: self::NAME,
            principal: $principal,
            metadata: $reason !== null ? ['reason' => $reason] : [],
        );
        $this->dispatcher?->dispatch(ToolRuntimeEvents::VERIFICATION_GRANTED, ['event' => new VerificationGrantedEvent($request, $result)]);

        return $result;
    }

    /**
     * Human/agent rejects the verification.
     */
    public function reject(VerificationRequest $request, string $principal, string $reason): VerificationResult
    {
        $result = VerificationResult::fail($reason, verifier: self::NAME, principal: $principal);
        $this->dispatcher?->dispatch(ToolRuntimeEvents::VERIFICATION_REJECTED, ['event' => new VerificationRejectedEvent($request, $result)]);

        return $result;
    }
}
