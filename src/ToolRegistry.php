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
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Milpa\ToolRuntime\RateLimiting\RateLimiterInterface;
use Milpa\ToolRuntime\Exceptions\ToolAlreadyRegisteredException;

/**
 * Enhanced Tool Registry with security, validation, and observability.
 *
 * Pipeline: resolve → validate → authorize → execute → audit
 *
 * Features:
 * - Token estimation for LLM context management
 * - Tool filtering by category and scopes
 * - Budget-aware tool selection
 */
class ToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    private SchemaValidator $validator;
    private PolicyGate $policyGate;
    private ConfirmationTokenStore $confirmationStore;
    private ToolAuditLogger $auditLogger;
    private TokenEstimator $tokenEstimator;
    private LoggerInterface $logger;
    private ?RateLimiterInterface $rateLimiter = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->validator = new SchemaValidator();
        $this->policyGate = new PolicyGate();
        $this->confirmationStore = new ConfirmationTokenStore();
        $this->auditLogger = new ToolAuditLogger($logger);
        $this->tokenEstimator = new TokenEstimator();
    }

    /**
     * Register a tool.
     *
     * @throws ToolAlreadyRegisteredException if tool name already exists
     */
    public function register(
        string $name,
        string $description,
        array $inputSchema,
        callable $callback,
        ?ToolOptions $options = null
    ): void {
        if (isset($this->tools[$name])) {
            throw new ToolAlreadyRegisteredException($name);
        }

        $options ??= new ToolOptions();

        $this->tools[$name] = new ToolDefinition(
            name: $name,
            description: $description,
            inputSchema: $inputSchema,
            callback: $callback,
            scopes: $options->scopes,
            mutating: $options->mutating,
            requiresConfirmation: $options->requiresConfirmation,
            timeout: $options->timeout ?? 30,
            clamps: $options->clamps,
            version: $options->version,
            outputSchema: $options->outputSchema
        );

    }

    /**
     * Get all tools for LLM/MCP exposure.
     *
     * @return array<array{name: string, description: string, inputSchema: array<mixed>, version?: string, outputSchema?: array<mixed>}>
     */
    public function getTools(): array
    {
        $list = [];
        foreach ($this->tools as $name => $tool) {
            $entry = [
                'name' => $name,
                'description' => $tool->description,
                'inputSchema' => !empty($tool->inputSchema) ? $tool->inputSchema : ['type' => 'object'],
            ];
            if ($tool->version !== null) {
                $entry['version'] = $tool->version;
            }
            if ($tool->outputSchema !== null) {
                $entry['outputSchema'] = $tool->outputSchema;
            }
            $list[] = $entry;
        }
        return $list;
    }

    /**
     * Get tools filtered by scopes.
     *
     * @param array<string> $scopes Required scopes
     *
     * @return array<array{name: string, description: string, inputSchema: array<mixed>}>
     */
    public function getToolsByScopes(array $scopes): array
    {
        $list = [];
        foreach ($this->tools as $name => $tool) {
            // Include tool if any of its scopes match
            if (empty($tool->scopes) || !empty(array_intersect($tool->scopes, $scopes))) {
                $list[] = [
                    'name' => $name,
                    'description' => $tool->description,
                    'inputSchema' => $tool->inputSchema,
                ];
            }
        }
        return $list;
    }

    /**
     * Get tools filtered by name prefix (category).
     *
     * @param string $prefix Tool name prefix (e.g., "list_", "admin_")
     *
     * @return array<array{name: string, description: string, inputSchema: array<mixed>}>
     */
    public function getToolsByPrefix(string $prefix): array
    {
        $list = [];
        foreach ($this->tools as $name => $tool) {
            if (str_starts_with($name, $prefix)) {
                $list[] = [
                    'name' => $name,
                    'description' => $tool->description,
                    'inputSchema' => $tool->inputSchema,
                ];
            }
        }
        return $list;
    }

    /**
     * Get tools that fit within token budget for a model.
     *
     * @param string             $model         Model identifier (e.g., "gpt-4", "claude-3-sonnet")
     * @param array<string>|null $priorityTools Tools to include first (by name)
     *
     * @return array{tools: array<array<mixed>>, excluded: array<string>, tokens_used: int, budget: int}
     */
    public function getToolsWithinBudget(string $model, ?array $priorityTools = null): array
    {
        $allTools = $this->getTools();

        // If priority tools specified, reorder to put them first
        if ($priorityTools !== null) {
            $prioritized = [];
            $others = [];

            foreach ($allTools as $tool) {
                if (in_array($tool['name'], $priorityTools, true)) {
                    $prioritized[] = $tool;
                } else {
                    $others[] = $tool;
                }
            }

            $allTools = array_merge($prioritized, $others);
        }

        $result = $this->tokenEstimator->selectToolsWithinBudget($allTools, $model);

        if (!empty($result['excluded'])) {
            $this->logger->warning(
                "[ToolRegistry] Token budget exceeded. Excluded " . count($result['excluded']) . " tools: " .
                implode(', ', array_slice($result['excluded'], 0, 5)) .
                (count($result['excluded']) > 5 ? '...' : '')
            );
        }

        return [
            'tools' => $result['selected'],
            'excluded' => $result['excluded'],
            'tokens_used' => $result['tokens_used'],
            'budget' => $result['budget'],
        ];
    }

    /**
     * Get token usage report for current tools.
     */
    public function getTokenUsageReport(string $model = 'gpt-4'): string
    {
        return $this->tokenEstimator->getUsageReport($this->getTools(), $model);
    }

    /**
     * Estimate tokens for current tools.
     *
     * @return array{total: int, per_tool: array<string, int>}
     */
    public function estimateTokens(): array
    {
        return $this->tokenEstimator->estimateToolsTokens($this->getTools());
    }

    /**
     * Check if tools fit within budget for a model.
     *
     * @return array{fits: bool, used: int, budget: int, percent: float, overflow: int, model_limit: int}
     */
    public function checkTokenBudget(string $model): array
    {
        return $this->tokenEstimator->checkToolBudget($this->getTools(), $model);
    }

    /**
     * Get the token estimator for direct use.
     */
    public function getTokenEstimator(): TokenEstimator
    {
        return $this->tokenEstimator;
    }

    /**
     * Build the runtime metadata array attached to every ToolResult.
     *
     * Replaces the former ToolMeta value object (meta is now a plain array on the
     * canonical ToolResult).
     *
     * @return array<string, mixed>
     */
    private function buildMeta(string $name, int $tookMs, ToolContext $ctx): array
    {
        return [
            'tool' => $name,
            'took_ms' => $tookMs,
            'request_id' => $ctx->request_id,
            'channel' => $ctx->channel,
            'principal' => $ctx->principal,
        ];
    }

    /**
     * Call a tool with full pipeline.
     *
     * @param string               $name Tool name
     * @param array<string, mixed> $args Arguments
     * @param ToolContext|null     $ctx  Execution context (defaults to CLI)
     *
     * @return ToolResult
     */
    public function call(string $name, array $args, ?ToolContext $ctx = null): ToolResult
    {
        $startTime = hrtime(true);
        $ctx = $ctx ?? ToolContext::cli();

        // 1. Resolve tool
        if (!isset($this->tools[$name])) {
            return ToolResult::error(
                "Tool not found: {$name}",
                null,
                $this->buildMeta($name, 0, $ctx) + ['code' => ToolResult::TOOL_NOT_FOUND]
            );
        }

        $tool = $this->tools[$name];

        // 2. Validate args
        if (!empty($tool->inputSchema)) {
            $validationResult = $this->validator->validate($args, $tool->inputSchema);
            if (!$validationResult->valid) {
                $this->auditLogger->logValidationFailure($ctx, $name, $validationResult->errors);
                return ToolResult::error(
                    $validationResult->getErrorMessage(),
                    null,
                    $this->buildMeta($name, $this->elapsed($startTime), $ctx) + ['code' => ToolResult::VALIDATION_ERROR]
                );
            }
        }

        // Apply clamps
        if (!empty($tool->clamps)) {
            $args = $this->validator->applyClamps($args, $tool->clamps);
        }

        // 3. Authorize
        // DEBUG: Log scope info
        $this->logger->debug("[ToolRegistry] Authorizing {$name} for principal={$ctx->principal}, channel={$ctx->channel}");
        $this->logger->debug("[ToolRegistry] Tool scopes required: " . implode(', ', $tool->scopes ?: ['(none)']));
        $this->logger->debug("[ToolRegistry] Context scopes: " . implode(', ', $ctx->scopes ?: ['(none)']));

        $authResult = $this->policyGate->authorize($ctx, $tool);
        $this->logger->debug("[ToolRegistry] Auth result: " . ($authResult->allowed ? 'ALLOWED' : 'DENIED: ' . ($authResult->reason ?? 'unknown')));

        if (!$authResult->allowed) {
            $this->auditLogger->logAuthFailure($ctx, $name, $authResult->reason ?? 'Unauthorized');
            return ToolResult::error(
                $authResult->reason ?? 'Unauthorized',
                null,
                $this->buildMeta($name, $this->elapsed($startTime), $ctx) + ['code' => ToolResult::FORBIDDEN]
            );
        }

        // 3.25 Rate limiting (if configured)
        if ($this->rateLimiter !== null) {
            $rateLimitKey = "{$ctx->channel}:{$ctx->principal}:{$name}";
            $cost = $tool->mutating ? 5 : 1;  // Mutating ops cost more

            $rateLimitResult = $this->rateLimiter->consume($rateLimitKey, $cost);

            if (!$rateLimitResult->allowed) {
                $took_ms = $this->elapsed($startTime);
                $this->auditLogger->log($ctx, $name, $args, false, ToolResult::RATE_LIMITED, $took_ms);
                return ToolResult::error(
                    $rateLimitResult->reason ?? 'Rate limit exceeded',
                    null,
                    $this->buildMeta($name, $took_ms, $ctx) + [
                        'code' => ToolResult::RATE_LIMITED,
                        'retry_after_seconds' => $rateLimitResult->retryAfterSeconds,
                    ]
                );
            }
        }

        // 3.5 Plan mode: return plan without executing
        // Plan mode validates and authorizes but does NOT execute the tool callback.
        // This allows preview of what would happen without side effects.
        if ($ctx->isPlanMode()) {
            $requiresConfirmation = $this->policyGate->requiresConfirmation($ctx, $tool);

            $this->logger->debug("[ToolRegistry] Plan mode for {$name} - returning plan without execution");

            return ToolResult::success([
                'plan' => [
                    'tool' => $name,
                    'mutating' => $tool->mutating,
                    'requires_confirmation' => $requiresConfirmation,
                    'scopes_required' => $tool->scopes,
                    'timeout' => $tool->timeout,
                    'args' => $args,  // Los args ya validados y clampeados
                ],
            ], null, $this->buildMeta($name, $this->elapsed($startTime), $ctx));
        }

        // 4. Check confirmation for destructive ops
        if ($this->policyGate->requiresConfirmation($ctx, $tool)) {
            // Check if confirm_token provided
            if (!isset($args['confirm_token'])) {
                // First call - return confirmation request
                $summary = $this->buildActionSummary($name, $args);
                $confirmToken = $this->confirmationStore->create($name, $args, $summary);
                return new ToolResult(
                    true,
                    [
                        'requires_confirmation' => true,
                        'confirm_token' => $confirmToken->token,
                        'action_summary' => $confirmToken->actionSummary,
                        'expires_at' => $confirmToken->expiresAt->format(\DateTimeInterface::ATOM),
                    ],
                    $confirmToken->actionSummary,
                    null,
                    $this->buildMeta($name, $this->elapsed($startTime), $ctx) + [
                        'type' => 'confirmation',
                        'requires_confirmation' => true,
                    ]
                );
            }

            // Second call - validate token
            $token = $args['confirm_token'];
            unset($args['confirm_token']); // Remove token from args

            $originalArgs = $this->confirmationStore->consume($token, $name);
            if ($originalArgs === null) {
                return ToolResult::error(
                    'Invalid or expired confirmation token',
                    null,
                    $this->buildMeta($name, $this->elapsed($startTime), $ctx) + ['code' => ToolResult::VALIDATION_ERROR]
                );
            }
            // Use original args (ignore any modifications in second call)
            $args = $originalArgs;
        }

        // 5. Execute
        try {
            // Inject context into args for tools that need it
            $args['_ctx'] = $ctx;

            // Track execution time for soft timeout enforcement
            $execStartTime = microtime(true);

            $result = call_user_func($tool->callback, $args);

            $executionTime = microtime(true) - $execStartTime;
            $timeoutSeconds = $tool->timeout;

            $took_ms = $this->elapsed($startTime);

            // Wrap raw result in ToolResult if callback doesn't return one
            if ($result instanceof ToolResult) {
                $toolResult = $result;
            } else {
                $toolResult = ToolResult::success($result, null, $this->buildMeta($name, $took_ms, $ctx));
            }

            // Soft timeout enforcement: log warning and add metadata if exceeded
            if ($executionTime > $timeoutSeconds) {
                $this->auditLogger->logTimeout($ctx, $name, $executionTime, $timeoutSeconds);
                $toolResult = $toolResult->withMeta([
                    'timeout_exceeded' => true,
                    'execution_time' => round($executionTime, 3),
                    'timeout_limit' => $timeoutSeconds,
                ]);
            }

            // 6. Audit
            $outputSize = is_string($result) ? strlen($result) : null;
            $this->auditLogger->log($ctx, $name, $args, true, null, $took_ms, $outputSize);

            return $toolResult;

        } catch (\Throwable $e) {
            $took_ms = $this->elapsed($startTime);

            $this->auditLogger->log($ctx, $name, $args, false, 'EXCEPTION', $took_ms);
            $this->logger->error("[ToolRegistry] {$name} threw exception: " . $e->getMessage());

            return ToolResult::error(
                $e->getMessage(),
                null,
                $this->buildMeta($name, $took_ms, $ctx) + ['code' => ToolResult::INTERNAL_ERROR]
            );
        }
    }

    /**
     * Check if a tool exists.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Get tool definition.
     */
    public function getDefinition(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Calculate elapsed time in milliseconds.
     */
    private function elapsed(int $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1_000_000);
    }

    /**
     * Build action summary for confirmation.
     *
     * @param array<string, mixed> $args
     */
    private function buildActionSummary(string $tool, array $args): string
    {
        $argsSummary = [];
        foreach ($args as $key => $value) {
            if (is_scalar($value)) {
                $argsSummary[] = "{$key}={$value}";
            }
        }
        return "{$tool}(" . implode(', ', array_slice($argsSummary, 0, 3)) . ")";
    }

    /**
     * Get policy gate for customization.
     */
    public function getPolicyGate(): PolicyGate
    {
        return $this->policyGate;
    }

    /**
     * Get confirmation store for customization.
     */
    public function getConfirmationStore(): ConfirmationTokenStore
    {
        return $this->confirmationStore;
    }

    /**
     * Set the rate limiter for tool call throttling.
     */
    public function setRateLimiter(RateLimiterInterface $limiter): void
    {
        $this->rateLimiter = $limiter;
    }

    /**
     * Get the rate limiter if configured.
     */
    public function getRateLimiter(): ?RateLimiterInterface
    {
        return $this->rateLimiter;
    }
}
