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

use Milpa\Enums\ApprovalPolicy;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Milpa\ValueObjects\Verification\VerificationContext;
use Milpa\ValueObjects\Verification\VerificationRequest;
use Milpa\ToolRuntime\ToolResult;

/**
 * Exposes the {@see HumanVerifier} as the `human_verify` tool (D8 / T089).
 *
 * Called without a `decision`, it opens the verification and surfaces through
 * {@see ToolResult::confirmation()} (PENDING). Called with `decision=grant|reject` and a
 * `principal`, it resolves and returns the verdict. The single registered callback is the
 * MCP `tools/call` surface — the same one engine as every other tool (D7).
 */
final class HumanVerifyTool
{
    public function __construct(private readonly HumanVerifier $verifier)
    {
    }

    /**
     * Register the `human_verify` tool callback on the given registry.
     *
     * Wires {@see handle()} as the single callback behind the MCP `tools/call` surface, marking
     * the tool as mutating. `requiresConfirmation` is deliberately `false` (tool-runtime 0.2):
     * `handle()` already owns its own two-phase `request_id` protocol — open a request, resolve
     * it later — so stacking the registry's generic confirm-token gate on top produced a
     * confusing 3-4 call choreography (registry gate -> redeem -> handle()'s own request phase
     * -> resolve, itself gated again) with no `request_id` visible until the SECOND call. With
     * the gate bypassed, `handle()` runs directly on every call: one call opens a request and
     * returns its `request_id` immediately, a second call with `decision` + that `request_id`
     * resolves it — the two-phase flow the tool was built around, reached directly through the
     * registry, not just by calling {@see handle()} out of band.
     *
     * Note: a channel whose policy sets `require_confirmation_for_mutating` (e.g. the built-in
     * `telegram` policy) still gates ANY `mutating: true` tool regardless of this flag — see
     * {@see \Milpa\ToolRuntime\PolicyGate::requiresConfirmation()}. The bypass is only total on
     * channels without that policy (e.g. `cli`, `mcp`, `web` by default).
     */
    public function register(ToolRegistryInterface $registry): void
    {
        $registry->register(
            HumanVerifier::NAME,
            'Open or resolve a human/agent verification for a subject. Omit "decision" to request '
                . '(returns a confirmation); set decision=grant|reject with a principal to resolve.',
            self::inputSchema(),
            /** @param array<string, mixed> $args */
            fn (array $args): ToolResult => $this->handle($args),
            new ToolOptions(mutating: true, requiresConfirmation: false),
        );
    }

    /**
     * Handle a `human_verify` tool call: open a verification request, or resolve a pending one.
     *
     * Omitting `decision` opens the request and returns a confirmation carrying its `request_id`;
     * passing `decision=grant|reject` with a `principal` resolves it via
     * {@see HumanVerifier::grant()} or {@see HumanVerifier::reject()}.
     *
     * @param array<string, mixed> $args
     */
    public function handle(array $args): ToolResult
    {
        $subject = trim((string) ($args['subject'] ?? ''));
        if ($subject === '') {
            return ToolResult::error(ToolResult::VALIDATION_ERROR, ['field' => 'subject', 'message' => 'subject is required']);
        }

        $policy = ApprovalPolicy::tryFrom((string) ($args['policy'] ?? 'single')) ?? ApprovalPolicy::SINGLE;
        $requestedBy = isset($args['requested_by']) ? (string) $args['requested_by'] : null;
        // Each call to handle() is a fresh invocation with no memory of a prior one, so the
        // request's correlation id (#7) can only survive the request -> resolve round trip if
        // the caller echoes it back via `request_id`. Preserve it instead of minting a new one
        // on resolve, which would otherwise silently disconnect the verdict from its request.
        $requestId = isset($args['request_id']) && (string) $args['request_id'] !== ''
            ? (string) $args['request_id']
            : null;

        $decision = isset($args['decision']) ? (string) $args['decision'] : '';

        // Request phase — no verdict yet.
        if ($decision === '') {
            $request = $requestId !== null
                ? new VerificationRequest($subject, $policy, requestedBy: $requestedBy, id: $requestId)
                : VerificationRequest::withGeneratedId($subject, $policy, requestedBy: $requestedBy);
            $this->verifier->verify($request, new VerificationContext(principal: $requestedBy));

            return ToolResult::confirmation(
                "Verification required for '{$subject}' (policy: {$policy->value}). "
                    . 'Resolve by calling again with decision=grant|reject, a principal, and '
                    . "request_id={$request->id}.",
                ['subject' => $subject, 'policy' => $policy->value, 'request_id' => $request->id],
                'verify',
                $subject,
                $subject,
            );
        }

        $request = new VerificationRequest($subject, $policy, requestedBy: $requestedBy, id: $requestId);

        // Resolve phase — needs the acting principal.
        $principal = trim((string) ($args['principal'] ?? ''));
        if ($principal === '') {
            return ToolResult::error(ToolResult::VALIDATION_ERROR, ['field' => 'principal', 'message' => 'principal is required to resolve']);
        }
        $reason = isset($args['reason']) ? (string) $args['reason'] : null;

        $result = match ($decision) {
            'grant' => $this->verifier->grant($request, $principal, $reason),
            'reject' => $this->verifier->reject($request, $principal, $reason ?? 'rejected'),
            default => null,
        };

        if ($result === null) {
            return ToolResult::error(ToolResult::VALIDATION_ERROR, ['field' => 'decision', 'message' => 'decision must be grant|reject']);
        }

        return ToolResult::success(
            $result->toArray(),
            $result->isSatisfied() ? "Verification granted for '{$subject}'." : "Verification rejected for '{$subject}'.",
            ['verification_status' => $result->status->value],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string', 'description' => 'Opaque id of what is being verified (e.g. "gate:report.publish").'],
                'policy' => ['type' => 'string', 'enum' => ['single', 'dual', 'quorum', 'auto'], 'default' => 'single'],
                'decision' => ['type' => 'string', 'enum' => ['grant', 'reject'], 'description' => 'Omit to request; set to resolve.'],
                'principal' => ['type' => 'string', 'description' => 'Opaque principal resolving the verification (required with decision).'],
                'reason' => ['type' => 'string', 'description' => 'Justification for the decision.'],
                'requested_by' => ['type' => 'string', 'description' => 'Opaque principal opening the request.'],
                'request_id' => ['type' => 'string', 'description' => 'Correlation id echoed back from the request phase; distinguishes concurrent verifications of the same subject. Omit on the initial request to auto-generate one.'],
            ],
            'required' => ['subject'],
        ];
    }
}
