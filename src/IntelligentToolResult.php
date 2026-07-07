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

use Milpa\ToolRuntime\Contracts\ToolIntelligence;

/**
 * Extended ToolResult with Intelligence layer.
 *
 * Follows the Intelligent Tool Protocol (ITP) format:
 * {
 *   "action": "create_note",
 *   "attempt": "Create note 'Design ideas'",
 *   "response": {...},
 *   "success": true,
 *   "reason": null,
 *   "intelligence": {
 *     "suggestions": [...],
 *     "warnings": [...],
 *     "next_actions": [...],
 *     "context": {...},
 *     "business_rules": {...}
 *   },
 *   "meta": {...}
 * }
 *
 * @package Milpa\ToolRuntime
 */
class IntelligentToolResult extends ToolResult
{
    /**
     * @param bool                  $success      Whether the operation succeeded
     * @param mixed                 $data         Response data
     * @param string|null           $message      Success message
     * @param string|null           $error        Error reason
     * @param array<string, mixed>  $meta         Additional metadata
     * @param ToolIntelligence|null $intelligence Intelligence layer
     * @param string|null           $action       Tool/action name
     * @param string|null           $attempt      Human-readable description of what was attempted
     */
    public function __construct(
        bool $success,
        mixed $data = null,
        ?string $message = null,
        ?string $error = null,
        array $meta = [],
        private readonly ?ToolIntelligence $intelligence = null,
        private readonly ?string $action = null,
        private readonly ?string $attempt = null
    ) {
        parent::__construct($success, $data, $message, $error, $meta);
    }

    // ========== Factory Methods ==========

    /**
     * Create from an existing ToolResult with added intelligence.
     *
     * @param ToolResult       $result       Original result
     * @param ToolIntelligence $intelligence Intelligence to add
     * @param string           $action       Tool/action name
     * @param string           $attempt      Human-readable attempt description
     */
    public static function fromToolResult(
        ToolResult $result,
        ToolIntelligence $intelligence,
        string $action,
        string $attempt
    ): self {
        return new self(
            success: $result->success,
            data: $result->data,
            message: $result->message,
            error: $result->error,
            meta: $result->meta,
            intelligence: $intelligence,
            action: $action,
            attempt: $attempt
        );
    }

    /**
     * Create a success result with intelligence.
     *
     * @param mixed                $data         Response data
     * @param ToolIntelligence     $intelligence Intelligence layer
     * @param string               $action       Tool/action name
     * @param string               $attempt      Human-readable attempt description
     * @param string|null          $message      Success message
     * @param array<string, mixed> $meta         Additional metadata
     */
    public static function successWithIntelligence(
        mixed $data,
        ToolIntelligence $intelligence,
        string $action,
        string $attempt,
        ?string $message = null,
        array $meta = []
    ): self {
        return new self(
            success: true,
            data: $data,
            message: $message,
            error: null,
            meta: $meta,
            intelligence: $intelligence,
            action: $action,
            attempt: $attempt
        );
    }

    /**
     * Create an error result with intelligence (suggestions for recovery).
     *
     * @param string               $error        Error reason
     * @param ToolIntelligence     $intelligence Intelligence with suggestions
     * @param string               $action       Tool/action name
     * @param string               $attempt      Human-readable attempt description
     * @param mixed                $data         Optional data (e.g., partial results)
     * @param array<string, mixed> $meta         Additional metadata
     */
    public static function errorWithIntelligence(
        string $error,
        ToolIntelligence $intelligence,
        string $action,
        string $attempt,
        mixed $data = null,
        array $meta = []
    ): self {
        return new self(
            success: false,
            data: $data,
            message: null,
            error: $error,
            meta: array_merge($meta, ['type' => 'error']),
            intelligence: $intelligence,
            action: $action,
            attempt: $attempt
        );
    }

    /**
     * Create a blocked result with alternative suggestions.
     *
     * @param string           $reason       Block reason
     * @param ToolIntelligence $intelligence Intelligence with alternatives
     * @param string           $action       Tool/action name
     * @param string           $attempt      Human-readable attempt description
     */
    public static function blockedWithIntelligence(
        string $reason,
        ToolIntelligence $intelligence,
        string $action,
        string $attempt
    ): self {
        return new self(
            success: false,
            data: null,
            message: null,
            error: $reason,
            meta: [
                'type' => 'blocked',
                'blocked_by_rule' => true,
            ],
            intelligence: $intelligence,
            action: $action,
            attempt: $attempt
        );
    }

    // ========== Getters ==========

    /**
     * Get the intelligence layer.
     */
    public function getIntelligence(): ?ToolIntelligence
    {
        return $this->intelligence;
    }

    /**
     * Check if this result has intelligence.
     */
    public function hasIntelligence(): bool
    {
        return $this->intelligence !== null && !$this->intelligence->isEmpty();
    }

    /**
     * Get the action name.
     */
    public function getAction(): ?string
    {
        return $this->action;
    }

    /**
     * Get the attempt description.
     */
    public function getAttempt(): ?string
    {
        return $this->attempt;
    }

    /**
     * Get suggestions from intelligence.
     *
     * @return array<string>
     */
    public function getSuggestions(): array
    {
        return $this->intelligence !== null ? $this->intelligence->suggestions : [];
    }

    /**
     * Get warnings from intelligence.
     *
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->intelligence !== null ? $this->intelligence->warnings : [];
    }

    /**
     * Get next actions from intelligence.
     *
     * @return array<array{tool: string, params?: array<string, mixed>, label: string}>
     */
    public function getNextActions(): array
    {
        return $this->intelligence !== null ? $this->intelligence->nextActions : [];
    }

    // ========== Builder Methods ==========

    /**
     * Add intelligence to this result (creates new instance).
     */
    public function withIntelligence(ToolIntelligence $intelligence): self
    {
        return new self(
            success: $this->success,
            data: $this->data,
            message: $this->message,
            error: $this->error,
            meta: $this->meta,
            intelligence: $intelligence,
            action: $this->action,
            attempt: $this->attempt
        );
    }

    /**
     * Merge additional intelligence into existing (creates new instance).
     */
    public function mergeIntelligence(ToolIntelligence $intelligence): self
    {
        $merged = $this->intelligence
            ? $this->intelligence->merge($intelligence)
            : $intelligence;

        return new self(
            success: $this->success,
            data: $this->data,
            message: $this->message,
            error: $this->error,
            meta: $this->meta,
            intelligence: $merged,
            action: $this->action,
            attempt: $this->attempt
        );
    }

    // ========== Serialization ==========

    /**
     * Serialize to ITP format.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $result = [
            'action' => $this->action,
            'attempt' => $this->attempt,
            'response' => $this->data,
            'success' => $this->success,
            'reason' => $this->error,
        ];

        // Only include intelligence if present and non-empty
        if ($this->hasIntelligence()) {
            $result['intelligence'] = $this->intelligence->toArray();
        }

        // Include meta if non-empty
        if (!empty($this->meta)) {
            $result['meta'] = $this->meta;
        }

        return $result;
    }

    /**
     * Convert to standard ToolResult format (without ITP wrapper).
     *
     * Useful for backwards compatibility with code expecting ToolResult format.
     *
     * @return array<string, mixed>
     */
    public function toStandardFormat(): array
    {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'message' => $this->message,
            'error' => $this->error,
            'meta' => $this->meta,
        ];
    }

    /**
     * Convert to compact format (minimal response).
     *
     * @return array<string, mixed>
     */
    public function toCompactFormat(): array
    {
        $result = [
            'success' => $this->success,
            'data' => $this->data,
        ];

        if ($this->error) {
            $result['error'] = $this->error;
        }

        // Only include key suggestions
        if ($this->hasIntelligence()) {
            $suggestions = $this->getSuggestions();
            if (!empty($suggestions)) {
                $result['suggestions'] = array_slice($suggestions, 0, 3);
            }

            $nextActions = $this->getNextActions();
            if (!empty($nextActions)) {
                $result['next_actions'] = array_slice($nextActions, 0, 3);
            }
        }

        return $result;
    }
}
