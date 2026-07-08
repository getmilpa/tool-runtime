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

namespace Milpa\ToolRuntime;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Events\ToolExecutedEvent;
use Milpa\ToolRuntime\Events\ToolFailedEvent;
use Psr\Log\LoggerInterface;

/**
 * Audit logger for tool calls.
 *
 * Logs all tool executions for security and debugging.
 *
 * Since tool-runtime 0.5, this also doubles as an event LISTENER: {@see onToolExecuted()} and
 * {@see onToolFailed()} are handlers for the `tool.executed` / `tool.failed` events dispatched by
 * {@see ToolRegistry::call()}, reproducing exactly the audit calls this class used to receive
 * imperatively. {@see ToolRegistry} wires these up automatically — subscribing them to its
 * dispatcher when one is supplied, and invoking them directly (bypassing the dispatcher) when
 * none is — so audit logging happens unconditionally either way; see the "no dispatcher = today's
 * behavior exactly" note on {@see ToolRegistry::__construct()}.
 *
 * `logValidationFailure()` / `logAuthFailure()` and the rate-limit branch's `log()` call remain
 * imperative call sites in {@see ToolRegistry::call()} — they fire BEFORE `tool.executing` is
 * ever dispatched (validation/authorization/rate-limiting are security gates that must run
 * unconditionally ahead of the interception point; see that method's security anchor), so there
 * is no `tool.*` event yet to hang them on in this package's catalog.
 */
class ToolAuditLogger
{
    private LoggerInterface $logger;

    /**
     * Fields to sanitize (mask) in args.
     *
     * @var list<string>
     */
    private array $sensitiveFields = ['password', 'token', 'secret', 'api_key', 'apiKey'];

    /**
     * Fields to completely exclude from audit logs.
     *
     * @var list<string>
     */
    private array $excludedFields = ['_ctx'];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Event listener for `tool.executed` (tool-runtime 0.5).
     *
     * Reproduces the audit call {@see ToolRegistry::call()} used to make imperatively on the
     * success path: {@see log()} with the outcome, plus {@see logTimeout()} when the result
     * carries the soft-timeout metadata. Fires identically for a cache short-circuit
     * (`$event->cacheServed === true`) — a cache hit is audited exactly like a normal execution,
     * never silently skipped.
     *
     * @param string                          $eventName Always `'tool.executed'`
     * @param array{event: ToolExecutedEvent} $payload   Dispatcher payload; `$payload['event']` is the {@see ToolExecutedEvent}
     */
    public function onToolExecuted(string $eventName, array $payload): void
    {
        $event = $payload['event'];
        $result = $event->result;

        $tookMs = (int) ($result->meta['took_ms'] ?? 0);
        $outputSize = is_string($result->data) ? strlen($result->data) : null;

        $this->log(
            $event->ctx,
            $event->name,
            $event->args,
            $result->success,
            $result->success ? null : ($result->error ?? ($result->meta['code'] ?? 'ERROR')),
            $tookMs,
            $outputSize
        );

        if (($result->meta['timeout_exceeded'] ?? false) === true) {
            $this->logTimeout(
                $event->ctx,
                $event->name,
                (float) ($result->meta['execution_time'] ?? 0.0),
                (int) ($result->meta['timeout_limit'] ?? 0)
            );
        }
    }

    /**
     * Event listener for `tool.failed` (tool-runtime 0.5).
     *
     * Reproduces the audit call {@see ToolRegistry::call()} used to make imperatively in its
     * exception handler: {@see log()} with `ok: false` and the `'EXCEPTION'` error code.
     *
     * @param string                        $eventName Always `'tool.failed'`
     * @param array{event: ToolFailedEvent} $payload   Dispatcher payload; `$payload['event']` is the {@see ToolFailedEvent}
     */
    public function onToolFailed(string $eventName, array $payload): void
    {
        $event = $payload['event'];

        $this->log($event->ctx, $event->name, $event->args, false, 'EXCEPTION', $event->tookMs);
    }

    /**
     * Log a tool call.
     *
     * @param array<string, mixed> $args
     */
    public function log(
        ToolContext $ctx,
        string $tool,
        array $args,
        bool $ok,
        ?string $errorCode,
        int $took_ms,
        ?int $outputSize = null
    ): void {
        $entry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'request_id' => $ctx->request_id,
            'principal' => $ctx->principal,
            'channel' => $ctx->channel,
            'tool' => $tool,
            'args' => $this->sanitizeArgs($args),
            'took_ms' => $took_ms,
            'ok' => $ok,
            'error_code' => $errorCode,
            'output_size' => $outputSize,
        ];

        if ($ctx->ip) {
            $entry['ip'] = $ctx->ip;
        }

        $logMessage = sprintf(
            "[ToolAudit] %s | %s | %s | %s | %dms | %s | args=%s",
            $ctx->request_id,
            $ctx->channel,
            $tool,
            $ctx->principal ?? 'anonymous',
            $took_ms,
            $ok ? 'OK' : "ERROR:{$errorCode}",
            json_encode($this->sanitizeArgs($args))
        );

        if ($ok) {
            $this->logger->info($logMessage);
        } else {
            $this->logger->warning($logMessage);
        }
    }

    /**
     * Log an authorization failure.
     */
    public function logAuthFailure(ToolContext $ctx, string $tool, string $reason): void
    {
        $this->logger->warning(
            sprintf(
                "[ToolAudit] AUTH_DENIED | %s | %s | %s | %s | reason=%s",
                $ctx->request_id,
                $ctx->channel,
                $tool,
                $ctx->principal ?? 'anonymous',
                $reason
            )
        );
    }

    /**
     * Log a validation failure.
     *
     * @param list<string> $errors
     */
    public function logValidationFailure(ToolContext $ctx, string $tool, array $errors): void
    {
        $this->logger->warning(
            sprintf(
                "[ToolAudit] VALIDATION_FAILED | %s | %s | %s | errors=%s",
                $ctx->request_id,
                $ctx->channel,
                $tool,
                json_encode($errors)
            )
        );
    }

    /**
     * Sanitize sensitive fields and exclude internal fields from args.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function sanitizeArgs(array $args): array
    {
        $sanitized = [];

        foreach ($args as $key => $value) {
            $keyStr = (string) $key;

            // Skip excluded fields entirely (like _ctx)
            if (in_array($keyStr, $this->excludedFields, true)) {
                continue;
            }

            if (in_array(strtolower($keyStr), $this->sensitiveFields, true)) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArgs($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Add a sensitive field to sanitize.
     */
    public function addSensitiveField(string $field): void
    {
        $this->sensitiveFields[] = strtolower($field);
    }

    /**
     * Log a timeout exceeded warning.
     *
     * Called when a tool execution exceeds its configured timeout.
     * This is a "soft" timeout - the result is still returned.
     */
    public function logTimeout(
        ToolContext $ctx,
        string $toolName,
        float $executionTime,
        int $timeoutLimit
    ): void {
        $this->logger->warning(
            sprintf(
                "[ToolRuntime] Timeout exceeded | %s | %s | %s | execution=%.3fs | limit=%ds",
                $ctx->request_id,
                $ctx->channel,
                $toolName,
                $executionTime,
                $timeoutLimit
            ),
            [
                'tool' => $toolName,
                'execution_time' => round($executionTime, 3),
                'timeout_limit' => $timeoutLimit,
                'principal' => $ctx->principal,
                'channel' => $ctx->channel,
                'request_id' => $ctx->request_id,
            ]
        );
    }
}
