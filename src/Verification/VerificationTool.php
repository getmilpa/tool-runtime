<?php

/**
 * This file is part of Milpa ToolRuntime — the AI tool-execution runtime of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Verification;

use Milpa\Enums\ApprovalPolicy;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Milpa\ValueObjects\Verification\VerificationContext;
use Milpa\ValueObjects\Verification\VerificationRequest;
use Milpa\ToolRuntime\ToolResult;

/**
 * Exposes {@see HumanVerifier} as two tools instead of one (D8 / T089, split in tool-runtime
 * 0.3): `request_verification` opens a verification and returns its `request_id`;
 * `resolve_verification` resolves a pending one with a `grant`/`reject` decision.
 *
 * Replaces the single combined verification tool tool-runtime 0.2 shipped, which mixed both
 * phases into one schema with conditional fields ("omit `decision` to request") and a name
 * that read as though the caller could grant its own request. The split gives each phase a
 * clean, non-overlapping schema — `request_verification` has no `decision`/`principal`
 * fields at all; `resolve_verification` requires them — and, as a direct consequence of
 * being separate tools, lets a host's policy allow `request_*` broadly while restricting
 * `resolve_*` to specific principals (the "policy dividend" — see the package README's
 * Verification section for a worked example using {@see self::$resolveScopes} /
 * {@see \Milpa\ValueObjects\Tooling\ToolOptions::$scopes}).
 *
 * Both tools register with `requiresConfirmation: false` — same reasoning as 0.2's
 * single-tool bypass, now applying to each half independently: `handleRequest()` /
 * `handleResolve()` together already ARE the two-phase confirmation protocol, so stacking the
 * registry's generic step-4 confirm-token gate on top would recreate the exact double-gate
 * choreography 0.2 killed. See {@see \Milpa\ToolRuntime\PolicyGate::requiresConfirmation()}
 * for the one channel-level exception (`require_confirmation_for_mutating`, e.g. the built-in
 * `telegram` policy) that still gates any `mutating: true` tool regardless of this flag.
 */
final class VerificationTool
{
    /** Registered name of the request-phase tool. */
    public const REQUEST_NAME = 'request_verification';

    /** Registered name of the resolve-phase tool. */
    public const RESOLVE_NAME = 'resolve_verification';

    /**
     * @param list<string> $resolveScopes Scopes required to call `resolve_verification` (empty
     *                                    = open to anyone who can reach the registry, matching
     *                                    the pre-split default). `request_verification` never
     *                                    takes a scopes parameter — it is always open; that
     *                                    asymmetry IS the policy dividend the split buys.
     */
    public function __construct(
        private readonly HumanVerifier $verifier,
        private readonly array $resolveScopes = [],
    ) {
    }

    /**
     * Register `request_verification` and `resolve_verification` on the given registry.
     */
    public function register(ToolRegistryInterface $registry): void
    {
        $registry->register(
            self::REQUEST_NAME,
            'Open a verification request for a subject. Returns a pending confirmation '
                . 'carrying the request_id needed to resolve it later via resolve_verification.',
            self::requestInputSchema(),
            /** @param array<string, mixed> $args */
            fn (array $args): ToolResult => $this->handleRequest($args),
            new ToolOptions(mutating: true, requiresConfirmation: false),
        );

        $registry->register(
            self::RESOLVE_NAME,
            'Resolve a pending verification request by request_id with a grant|reject '
                . 'decision from a principal.',
            self::resolveInputSchema(),
            /** @param array<string, mixed> $args */
            fn (array $args): ToolResult => $this->handleResolve($args),
            new ToolOptions(mutating: true, requiresConfirmation: false, scopes: $this->resolveScopes),
        );
    }

    /**
     * Handle a `request_verification` call: open a verification request for `subject`.
     *
     * Always opens a new request and returns {@see ToolResult::confirmation()} carrying its
     * `request_id` — this tool never resolves a verdict itself, only {@see handleResolve()}
     * does.
     *
     * @param array<string, mixed> $args
     */
    public function handleRequest(array $args): ToolResult
    {
        $subject = trim((string) ($args['subject'] ?? ''));
        if ($subject === '') {
            return ToolResult::error(ToolResult::VALIDATION_ERROR, ['field' => 'subject', 'message' => 'subject is required']);
        }

        $policy = ApprovalPolicy::tryFrom((string) ($args['policy'] ?? 'single')) ?? ApprovalPolicy::SINGLE;
        $requestedBy = isset($args['requested_by']) ? (string) $args['requested_by'] : null;
        // The correlation id (#7) can only survive the request -> resolve round trip if the
        // caller echoes it back on resolve_verification's request_id, so preserve a caller-
        // supplied one instead of always minting a fresh id.
        $requestId = isset($args['request_id']) && (string) $args['request_id'] !== ''
            ? (string) $args['request_id']
            : null;

        $request = $requestId !== null
            ? new VerificationRequest($subject, $policy, requestedBy: $requestedBy, id: $requestId)
            : VerificationRequest::withGeneratedId($subject, $policy, requestedBy: $requestedBy);
        $this->verifier->verify($request, new VerificationContext(principal: $requestedBy));

        return ToolResult::confirmation(
            "Verification required for '{$subject}' (policy: {$policy->value}). "
                . 'Resolve by calling resolve_verification with decision=grant|reject, a '
                . "principal, and request_id={$request->id}.",
            ['subject' => $subject, 'policy' => $policy->value, 'request_id' => $request->id],
            'verify',
            $subject,
            $subject,
        );
    }

    /**
     * Handle a `resolve_verification` call: resolve a pending verification by `request_id`.
     *
     * `subject` is optional here — when omitted, it falls back to `request_id` itself (still
     * a non-empty, opaque correlation string) so {@see VerificationRequest}'s non-empty-subject
     * invariant holds even when the caller does not echo the original subject back. Callers
     * that want the true subject reachable from the dispatched `verification.granted` /
     * `verification.rejected` events should echo it explicitly.
     *
     * @param array<string, mixed> $args
     */
    public function handleResolve(array $args): ToolResult
    {
        $requestId = trim((string) ($args['request_id'] ?? ''));
        if ($requestId === '') {
            return ToolResult::error(ToolResult::VALIDATION_ERROR, ['field' => 'request_id', 'message' => 'request_id is required']);
        }

        $decision = trim((string) ($args['decision'] ?? ''));
        if (!in_array($decision, ['grant', 'reject'], true)) {
            return ToolResult::error(ToolResult::VALIDATION_ERROR, ['field' => 'decision', 'message' => 'decision must be grant|reject']);
        }

        $principal = trim((string) ($args['principal'] ?? ''));
        if ($principal === '') {
            return ToolResult::error(ToolResult::VALIDATION_ERROR, ['field' => 'principal', 'message' => 'principal is required']);
        }

        $subject = isset($args['subject']) && trim((string) $args['subject']) !== ''
            ? trim((string) $args['subject'])
            : $requestId;
        $reason = isset($args['reason']) ? (string) $args['reason'] : null;

        $request = new VerificationRequest($subject, ApprovalPolicy::SINGLE, id: $requestId);

        $result = $decision === 'grant'
            ? $this->verifier->grant($request, $principal, $reason)
            : $this->verifier->reject($request, $principal, $reason ?? 'rejected');

        return ToolResult::success(
            $result->toArray(),
            $result->isSatisfied() ? "Verification granted for '{$subject}'." : "Verification rejected for '{$subject}'.",
            ['verification_status' => $result->status->value],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string', 'description' => 'Opaque id of what is being verified (e.g. "gate:report.publish").'],
                'policy' => ['type' => 'string', 'enum' => ['single', 'dual', 'quorum', 'auto'], 'default' => 'single'],
                'requested_by' => ['type' => 'string', 'description' => 'Opaque principal opening the request.'],
                'request_id' => ['type' => 'string', 'description' => 'Correlation id to use for this request; omit to auto-generate one.'],
            ],
            'required' => ['subject'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'string', 'description' => 'Correlation id returned by request_verification.'],
                'decision' => ['type' => 'string', 'enum' => ['grant', 'reject'], 'description' => 'The verdict.'],
                'principal' => ['type' => 'string', 'description' => 'Opaque principal resolving the verification.'],
                'subject' => ['type' => 'string', 'description' => 'Opaque id of what is being verified; falls back to request_id if omitted.'],
                'reason' => ['type' => 'string', 'description' => 'Justification for the decision.'],
            ],
            'required' => ['request_id', 'decision', 'principal'],
        ];
    }
}
