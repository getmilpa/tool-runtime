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

/**
 * Simple token estimator for LLM context management.
 *
 * Uses character-based estimation (4 chars ≈ 1 token for English).
 * This is an approximation - actual tokenization varies by model.
 *
 * For more accurate counting, integrate with:
 * - tiktoken (OpenAI's tokenizer)
 * - Anthropic's token counting API
 */
class TokenEstimator
{
    /**
     * Average characters per token.
     * - English text: ~4 chars/token
     * - Code/JSON: ~3-4 chars/token
     * - Non-English: varies widely
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Known context limits by model family.
     *
     * Claude models support up to 200K tokens context.
     * GPT-4 Turbo/4o support 128K tokens.
     */
    public const MODEL_LIMITS = [
        // GPT models
        'gpt-3.5-turbo' => 4096,
        'gpt-4' => 8192,
        'gpt-4-32k' => 32768,
        'gpt-4-turbo' => 128000,
        'gpt-4o' => 128000,
        // Claude 2.x
        'claude-2' => 100000,
        // Claude 3.x (old naming)
        'claude-3-haiku' => 200000,
        'claude-3-sonnet' => 200000,
        'claude-3-opus' => 200000,
        'claude-3.5-sonnet' => 200000,
        // Claude 3.x/4.x (new naming: claude-{variant}-{version})
        'claude-sonnet' => 200000,
        'claude-haiku' => 200000,
        'claude-opus' => 200000,
    ];

    /**
     * Recommended max percentage of context for tools.
     * Leave room for system prompt, conversation history, and response.
     */
    public const RECOMMENDED_TOOL_BUDGET_PERCENT = 30;

    /**
     * Estimate token count for a string.
     */
    public function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Estimate tokens for a tool definition.
     *
     * @param array{name: string, description: string, inputSchema: array<mixed>} $tool
     */
    public function estimateToolTokens(array $tool): int
    {
        $json = json_encode($tool, JSON_UNESCAPED_UNICODE);
        return $this->estimateTokens($json ?: '');
    }

    /**
     * Estimate tokens for multiple tools.
     *
     * @param array<array{name: string, description: string, inputSchema: array<mixed>}> $tools
     *
     * @return array{total: int, per_tool: array<string, int>}
     */
    public function estimateToolsTokens(array $tools): array
    {
        $total = 0;
        $perTool = [];

        foreach ($tools as $tool) {
            $tokens = $this->estimateToolTokens($tool);
            $perTool[$tool['name']] = $tokens;
            $total += $tokens;
        }

        return [
            'total' => $total,
            'per_tool' => $perTool,
        ];
    }

    /**
     * Get context limit for a model.
     */
    public function getModelLimit(string $model): int
    {
        // Check exact match
        if (isset(self::MODEL_LIMITS[$model])) {
            return self::MODEL_LIMITS[$model];
        }

        // Check partial match (e.g., "gpt-4o-2024-05-13" matches "gpt-4o")
        foreach (self::MODEL_LIMITS as $prefix => $limit) {
            if (str_starts_with($model, $prefix)) {
                return $limit;
            }
        }

        // Default to conservative GPT-4 limit
        return 8192;
    }

    /**
     * Get recommended token budget for tools based on model.
     */
    public function getToolBudget(string $model): int
    {
        $limit = $this->getModelLimit($model);
        return (int) floor($limit * self::RECOMMENDED_TOOL_BUDGET_PERCENT / 100);
    }

    /**
     * Check if tools fit within budget.
     *
     * @param array<array{name: string, description: string, inputSchema: array<mixed>}> $tools
     *
     * @return array{fits: bool, used: int, budget: int, percent: float, overflow: int, model_limit: int}
     */
    public function checkToolBudget(array $tools, string $model): array
    {
        $estimate = $this->estimateToolsTokens($tools);
        $budget = $this->getToolBudget($model);
        $used = $estimate['total'];
        $percent = $budget > 0 ? ($used / $budget) * 100 : 100;
        $overflow = max(0, $used - $budget);

        return [
            'fits' => $used <= $budget,
            'used' => $used,
            'budget' => $budget,
            'percent' => round($percent, 2),
            'overflow' => $overflow,
            'model_limit' => $this->getModelLimit($model),
        ];
    }

    /**
     * Select tools that fit within budget, prioritizing by order.
     *
     * @param array<array{name: string, description: string, inputSchema: array<mixed>}> $tools
     *
     * @return array{selected: array<array<mixed>>, excluded: array<string>, tokens_used: int, budget: int}
     */
    public function selectToolsWithinBudget(array $tools, string $model): array
    {
        $budget = $this->getToolBudget($model);
        $selected = [];
        $excluded = [];
        $tokensUsed = 0;

        foreach ($tools as $tool) {
            $toolTokens = $this->estimateToolTokens($tool);

            if ($tokensUsed + $toolTokens <= $budget) {
                $selected[] = $tool;
                $tokensUsed += $toolTokens;
            } else {
                $excluded[] = $tool['name'];
            }
        }

        return [
            'selected' => $selected,
            'excluded' => $excluded,
            'tokens_used' => $tokensUsed,
            'budget' => $budget,
        ];
    }

    /**
     * Get a summary report of token usage.
     *
     * @param array<array{name: string, description: string, inputSchema: array<mixed>}> $tools
     */
    public function getUsageReport(array $tools, string $model): string
    {
        $estimate = $this->estimateToolsTokens($tools);
        $budget = $this->checkToolBudget($tools, $model);

        $lines = [
            "=== Token Usage Report ===",
            "Model: {$model}",
            "Context Limit: {$budget['model_limit']} tokens",
            "Tool Budget (30%): {$budget['budget']} tokens",
            "",
            "Tools Registered: " . count($tools),
            "Tokens Used: {$budget['used']} tokens",
            "Budget Usage: {$budget['percent']}%",
            "",
            "Status: " . ($budget['fits'] ? "✅ Within budget" : "⚠️ OVER BUDGET by {$budget['overflow']} tokens"),
            "",
            "Top 10 Tools by Token Usage:",
        ];

        // Sort by tokens descending
        arsort($estimate['per_tool']);
        $i = 0;
        foreach ($estimate['per_tool'] as $name => $tokens) {
            if (++$i > 10) {
                break;
            }
            $lines[] = "  - {$name}: {$tokens} tokens";
        }

        return implode("\n", $lines);
    }
}
