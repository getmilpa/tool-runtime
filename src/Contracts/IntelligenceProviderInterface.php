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

namespace Milpa\ToolRuntime\Contracts;

/**
 * Interface for Intelligence Providers.
 *
 * Intelligence Providers analyze tool execution context and generate
 * enriched information (suggestions, warnings, next actions, etc.)
 * that helps the LLM make better decisions.
 *
 * Providers can run in two phases:
 * - Pre-execution: Before the tool runs (for warnings, would_block)
 * - Post-execution: After the tool runs (for suggestions, next_actions)
 *
 * @package Milpa\ToolRuntime\Contracts
 */
interface IntelligenceProviderInterface
{
    /**
     * Get provider name for identification and debugging.
     *
     * @return string Unique provider name (e.g., "entity_completeness", "next_actions")
     */
    public function getName(): string;

    /**
     * Check if this provider applies to the given context.
     *
     * Use this to filter providers by:
     * - Tool name patterns (e.g., only for create_* tools)
     * - Entity types (e.g., only for Note entities)
     * - Execution phase (e.g., only post-execution)
     *
     * @param IntelligenceContext $context The execution context
     *
     * @return bool True if this provider should run
     */
    public function supports(IntelligenceContext $context): bool;

    /**
     * Generate intelligence for the given context.
     *
     * Called by the IntelligenceAggregator when supports() returns true.
     * Should return a ToolIntelligence with relevant information.
     *
     * @param IntelligenceContext $context The execution context
     *
     * @return ToolIntelligence The generated intelligence
     */
    public function provide(IntelligenceContext $context): ToolIntelligence;

    /**
     * Get provider priority.
     *
     * Higher priority providers run first and their output
     * can influence later providers.
     *
     * Recommended ranges:
     * - 100+: Core providers (completeness, business rules)
     * - 50-99: Standard providers (next actions, suggestions)
     * - 0-49: Custom/plugin providers
     *
     * @return int Priority value (higher = runs first)
     */
    public function getPriority(): int;

    /**
     * Get the execution phase for this provider.
     *
     * - "pre": Runs before tool execution (for warnings, would_block)
     * - "post": Runs after tool execution (for suggestions, next_actions, context)
     * - "both": Runs in both phases
     *
     * @return string One of: "pre", "post", "both"
     */
    public function getPhase(): string;
}
