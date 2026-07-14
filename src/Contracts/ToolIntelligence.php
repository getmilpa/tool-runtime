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
 * Intelligence data attached to tool results.
 *
 * This DTO carries enriched information that helps the LLM:
 * - suggestions: Proactive tips to improve operations
 * - warnings: Non-blocking alerts about potential issues
 * - nextActions: Suggested follow-up tool calls
 * - context: Entity completeness, relationships, capacity
 * - businessRules: Applied rules, potential blocks, tips
 *
 * @package Milpa\ToolRuntime
 */
class ToolIntelligence implements \JsonSerializable
{
    /**
     * @param array<int, string>                                                            $suggestions   Proactive improvement suggestions
     * @param array<int, string>                                                            $warnings      Non-blocking warnings
     * @param array<int, array{tool: string, params?: array<string, mixed>, label: string}> $nextActions   Suggested follow-up actions
     * @param array<string, mixed>                                                          $context       Entity context (completeness, relationships, capacity)
     * @param array<string, mixed>                                                          $businessRules Business rules state (applied, would_block, tips)
     */
    public function __construct(
        public readonly array $suggestions = [],
        public readonly array $warnings = [],
        public readonly array $nextActions = [],
        public readonly array $context = [],
        public readonly array $businessRules = []
    ) {
    }

    /**
     * Create an empty intelligence instance.
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create from array data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            suggestions: $data['suggestions'] ?? [],
            warnings: $data['warnings'] ?? [],
            nextActions: $data['next_actions'] ?? $data['nextActions'] ?? [],
            context: $data['context'] ?? [],
            businessRules: $data['business_rules'] ?? $data['businessRules'] ?? []
        );
    }

    // ========== Builder Methods (Immutable) ==========

    /**
     * Add suggestions to a new instance.
     *
     * @param array<string> $suggestions
     */
    public function withSuggestions(array $suggestions): self
    {
        return new self(
            array_merge($this->suggestions, $suggestions),
            $this->warnings,
            $this->nextActions,
            $this->context,
            $this->businessRules
        );
    }

    /**
     * Add warnings to a new instance.
     *
     * @param array<string> $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self(
            $this->suggestions,
            array_merge($this->warnings, $warnings),
            $this->nextActions,
            $this->context,
            $this->businessRules
        );
    }

    /**
     * Add next actions to a new instance.
     *
     * @param array<int, array{tool: string, params?: array<string, mixed>, label: string}> $nextActions
     */
    public function withNextActions(array $nextActions): self
    {
        return new self(
            $this->suggestions,
            $this->warnings,
            array_merge($this->nextActions, $nextActions),
            $this->context,
            $this->businessRules
        );
    }

    /**
     * Set context in a new instance.
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return new self(
            $this->suggestions,
            $this->warnings,
            $this->nextActions,
            array_merge($this->context, $context),
            $this->businessRules
        );
    }

    /**
     * Set business rules in a new instance.
     *
     * @param array<string, mixed> $businessRules Business rules (applied, would_block, tips)
     */
    public function withBusinessRules(array $businessRules): self
    {
        return new self(
            $this->suggestions,
            $this->warnings,
            $this->nextActions,
            $this->context,
            array_merge_recursive($this->businessRules, $businessRules)
        );
    }

    /**
     * Merge another ToolIntelligence into this one.
     */
    public function merge(self $other): self
    {
        return new self(
            array_merge($this->suggestions, $other->suggestions),
            array_merge($this->warnings, $other->warnings),
            array_merge($this->nextActions, $other->nextActions),
            array_merge($this->context, $other->context),
            array_merge_recursive($this->businessRules, $other->businessRules)
        );
    }

    // ========== Helpers ==========

    /**
     * Check if this intelligence has any content.
     */
    public function isEmpty(): bool
    {
        return empty($this->suggestions)
            && empty($this->warnings)
            && empty($this->nextActions)
            && empty($this->context)
            && empty($this->businessRules);
    }

    /**
     * Check if there are any warnings.
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Check if there are potential blocking rules.
     */
    public function hasBlockingRules(): bool
    {
        return !empty($this->businessRules['would_block'] ?? []);
    }

    /**
     * Get completeness percentage from context.
     */
    public function getCompleteness(): float
    {
        return (float) ($this->context['completeness'] ?? 1.0);
    }

    /**
     * Get missing recommended fields from context.
     *
     * @return array<string>
     */
    public function getMissingRecommended(): array
    {
        return $this->context['missing_recommended'] ?? [];
    }

    // ========== Serialization ==========

    /**
     * Convert to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        // Only include non-empty sections
        if (!empty($this->suggestions)) {
            $result['suggestions'] = $this->suggestions;
        }
        if (!empty($this->warnings)) {
            $result['warnings'] = $this->warnings;
        }
        if (!empty($this->nextActions)) {
            $result['next_actions'] = $this->nextActions;
        }
        if (!empty($this->context)) {
            $result['context'] = $this->context;
        }
        if (!empty($this->businessRules)) {
            $result['business_rules'] = $this->businessRules;
        }

        return $result;
    }

    /**
     * Serialize for JSON encoding; delegates to {@see toArray()} so empty sections are omitted.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
