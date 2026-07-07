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
use Psr\Log\LoggerInterface;

/**
 * Audit logger for tool calls.
 *
 * Logs all tool executions for security and debugging.
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
