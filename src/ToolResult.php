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

namespace Milpa\ToolRuntime;

/**
 * Structured result from tool execution.
 *
 * All tools should return ToolResult for consistent rendering across channels.
 */
class ToolResult implements \JsonSerializable
{
    // Canonical error codes (replace the former Contracts\ToolError class).
    public const TOOL_NOT_FOUND = 'TOOL_NOT_FOUND';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const NOT_FOUND = 'NOT_FOUND';
    public const UNAUTHORIZED = 'UNAUTHORIZED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const CONFIRMATION_REQUIRED = 'CONFIRMATION_REQUIRED';
    public const TIMEOUT = 'TIMEOUT';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
    public const RATE_LIMITED = 'RATE_LIMITED';

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data = null,
        public readonly ?string $message = null,
        public readonly ?string $error = null,
        public readonly array $meta = []
    ) {
    }

    // ========== Factory Methods ==========

    /**
     * Create a success result.
     *
     * @param array<string, mixed> $meta
     */
    public static function success(mixed $data = null, ?string $message = null, array $meta = []): self
    {
        return new self(true, $data, $message, null, $meta);
    }

    /**
     * Create an error result.
     *
     * @param array<string, mixed> $meta
     */
    public static function error(string $error, mixed $data = null, array $meta = []): self
    {
        return new self(false, $data, null, $error, array_merge($meta, ['type' => 'error']));
    }

    /**
     * Create a paginated list result.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $extraMeta
     */
    public static function paginated(
        array $items,
        int $page,
        int $totalItems,
        int $limit,
        ?string $message = null,
        array $extraMeta = []
    ): self {
        $totalPages = (int) ceil($totalItems / $limit);

        return new self(
            true,
            $items,
            $message,
            null,
            array_merge($extraMeta, [
                'type' => 'list',
                'pagination' => [
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'total_items' => $totalItems,
                    'limit' => $limit,
                    'has_prev' => $page > 1,
                    'has_next' => $page < $totalPages,
                ],
            ])
        );
    }

    /**
     * Create a detail/single item result.
     *
     * @param array<string, mixed> $item
     * @param list<mixed>          $actions
     */
    public static function detail(
        array $item,
        string $entity,
        ?string $message = null,
        array $actions = []
    ): self {
        return new self(
            true,
            $item,
            $message,
            null,
            [
                'type' => 'detail',
                'entity' => $entity,
                'actions' => $actions,
            ]
        );
    }

    /**
     * Create a confirmation request result.
     *
     * @param array<string, mixed> $details
     */
    public static function confirmation(
        string $message,
        array $details,
        string $action,
        string $target,
        int|string $targetId
    ): self {
        return new self(
            true,
            $details,
            $message,
            null,
            [
                'type' => 'confirmation',
                'requires_confirmation' => true,
                'action' => $action,
                'target' => $target,
                'target_id' => $targetId,
            ]
        );
    }

    /**
     * Create a blocked result (by business rule).
     */
    public static function blocked(string $reason, ?string $suggestion = null): self
    {
        return new self(
            false,
            null,
            null,
            $reason,
            [
                'type' => 'blocked',
                'blocked_by_rule' => true,
                'suggestion' => $suggestion,
            ]
        );
    }

    // ========== Helpers ==========

    /**
     * Check if this result requires confirmation.
     */
    public function requiresConfirmation(): bool
    {
        return self::asksForConfirmation($this->toArray());
    }

    /**
     * Whether a payload that already travelled is a call ASKING rather than a call that DID.
     *
     * ── WHY A STATIC ON TOP OF THE INSTANCE PREDICATE ───────────────────────────────────────────
     *
     * A confirmation request and a completed write both come back successful, so a consumer holding
     * only the serialized result cannot separate «it asked for a token» from «it wrote». A session
     * log that cannot separate them counts two mutations where there was one — and that is the count
     * that governs consent (greenhouse `evidence/0200`).
     *
     * Downstream holds a string, not an object, and the rule lives here because this package owns the
     * protocol. A second reader of `requires_confirmation` elsewhere disagrees with this one the day
     * either changes.
     *
     * ── THE FLAG IS NOT READ WHEREVER IT APPEARS ────────────────────────────────────────────────
     *
     * A plan-mode result carries `requires_confirmation` inside its plan: it says what WOULD need
     * confirming, it is not itself asking. Matching the key at any depth would record a plan as a
     * pending mutation, which is the opposite of what a plan is.
     *
     * @param mixed $payload The serialized result, decoded or as JSON: the full envelope, where the
     *                       flag rides under `meta`, or the data payload that reaches a model, where
     *                       it rides at the top.
     */
    public static function asksForConfirmation(mixed $payload): bool
    {
        if (\is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!\is_array($payload)) {
            return false;
        }

        $meta = $payload['meta'] ?? null;
        if (\is_array($meta) && ($meta['requires_confirmation'] ?? false) === true) {
            return true;
        }

        return ($payload['requires_confirmation'] ?? false) === true;
    }

    /**
     * Check if this result was blocked by a business rule.
     */
    public function isBlocked(): bool
    {
        return ($this->meta['blocked_by_rule'] ?? false) === true;
    }

    /**
     * Get the result type.
     */
    public function getType(): string
    {
        return $this->meta['type'] ?? 'generic';
    }

    /**
     * Get pagination info if present.
     *
     * @return array{page: int, total_pages: int, total_items: int, limit: int, has_prev: bool, has_next: bool}|null
     */
    public function getPagination(): ?array
    {
        return $this->meta['pagination'] ?? null;
    }

    /**
     * Get actions if present.
     *
     * @return list<mixed>
     */
    public function getActions(): array
    {
        return $this->meta['actions'] ?? [];
    }

    /**
     * Get the confirmation token, if this result carries a confirmation request.
     */
    public function getConfirmToken(): ?string
    {
        return is_array($this->data) ? ($this->data['confirm_token'] ?? null) : null;
    }

    /**
     * Return a copy with extra metadata merged into meta.
     *
     * Replaces the former ToolResult(A)::withMetadata(); the runtime uses it to
     * attach soft-timeout info without rebuilding the result.
     *
     * @param array<string, mixed> $extra
     */
    public function withMeta(array $extra): self
    {
        return new self(
            $this->success,
            $this->data,
            $this->message,
            $this->error,
            array_merge($this->meta, $extra)
        );
    }

    // ========== Serialization ==========

    /**
     * Serialize to the wire shape consumed by every channel renderer and the MCP transport.
     *
     * @return array{success: bool, data: mixed, message: ?string, error: ?string, meta: array<string, mixed>}
     */
    public function jsonSerialize(): array
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
     * Encode this result as pretty-printed, unescaped-unicode JSON.
     */
    public function toJson(): string
    {
        return json_encode($this, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Convert to a plain array; same shape as {@see jsonSerialize()}.
     *
     * @return array{success: bool, data: mixed, message: ?string, error: ?string, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
