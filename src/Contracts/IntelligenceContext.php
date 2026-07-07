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

namespace Milpa\ToolRuntime\Contracts;

use Milpa\ToolRuntime\ToolResult;

/**
 * Context object passed to Intelligence Providers.
 *
 * Carries all information needed to generate intelligent suggestions:
 * - Tool name and arguments
 * - Execution result (for post-execution providers)
 * - Entity information (type, instance, ID)
 * - Applied business rules
 *
 * @package Milpa\ToolRuntime
 */
class IntelligenceContext
{
    /**
     * @param string                                      $toolName     Name of the tool being executed
     * @param array<string, mixed>                        $args         Arguments passed to the tool
     * @param ToolResult|null                             $result       Execution result (null for pre-execution)
     * @param string|null                                 $entityType   Type of entity involved (Note, User, etc.)
     * @param object|null                                 $entity       The actual entity instance
     * @param int|string|null                             $entityId     Entity identifier
     * @param array<array{rule: string, effect?: string}> $appliedRules Rules that were applied
     * @param array<string, mixed>                        $extra        Additional context data
     */
    public function __construct(
        private readonly string $toolName,
        private readonly array $args = [],
        private readonly ?ToolResult $result = null,
        private readonly ?string $entityType = null,
        private readonly ?object $entity = null,
        private readonly int|string|null $entityId = null,
        private readonly array $appliedRules = [],
        private readonly array $extra = []
    ) {
    }

    // ========== Factory Methods ==========

    /**
     * Create context for pre-execution phase (before tool runs).
     *
     * @param string               $toolName
     * @param array<string, mixed> $args
     * @param array<string, mixed> $extra
     */
    public static function preExecution(
        string $toolName,
        array $args = [],
        array $extra = []
    ): self {
        return new self(
            toolName: $toolName,
            args: $args,
            extra: $extra
        );
    }

    /**
     * Create context for post-execution phase (after tool runs).
     *
     * @param string                                      $toolName
     * @param array<string, mixed>                        $args
     * @param ToolResult                                  $result
     * @param string|null                                 $entityType
     * @param object|null                                 $entity
     * @param int|string|null                             $entityId
     * @param array<array{rule: string, effect?: string}> $appliedRules
     * @param array<string, mixed>                        $extra
     */
    public static function postExecution(
        string $toolName,
        array $args,
        ToolResult $result,
        ?string $entityType = null,
        ?object $entity = null,
        int|string|null $entityId = null,
        array $appliedRules = [],
        array $extra = []
    ): self {
        return new self(
            toolName: $toolName,
            args: $args,
            result: $result,
            entityType: $entityType,
            entity: $entity,
            entityId: $entityId,
            appliedRules: $appliedRules,
            extra: $extra
        );
    }

    // ========== Builder Methods (Immutable) ==========

    /**
     * Add execution result to create post-execution context.
     */
    public function withResult(ToolResult $result): self
    {
        return new self(
            toolName: $this->toolName,
            args: $this->args,
            result: $result,
            entityType: $this->entityType,
            entity: $this->entity,
            entityId: $this->entityId,
            appliedRules: $this->appliedRules,
            extra: $this->extra
        );
    }

    /**
     * Add entity information.
     */
    public function withEntity(string $type, ?object $entity, int|string|null $id = null): self
    {
        return new self(
            toolName: $this->toolName,
            args: $this->args,
            result: $this->result,
            entityType: $type,
            entity: $entity,
            entityId: $id,
            appliedRules: $this->appliedRules,
            extra: $this->extra
        );
    }

    /**
     * Add applied business rules.
     *
     * @param array<array{rule: string, effect?: string}> $rules
     */
    public function withAppliedRules(array $rules): self
    {
        return new self(
            toolName: $this->toolName,
            args: $this->args,
            result: $this->result,
            entityType: $this->entityType,
            entity: $this->entity,
            entityId: $this->entityId,
            appliedRules: array_merge($this->appliedRules, $rules),
            extra: $this->extra
        );
    }

    /**
     * Add extra context data.
     *
     * @param array<string, mixed> $extra
     */
    public function withExtra(array $extra): self
    {
        return new self(
            toolName: $this->toolName,
            args: $this->args,
            result: $this->result,
            entityType: $this->entityType,
            entity: $this->entity,
            entityId: $this->entityId,
            appliedRules: $this->appliedRules,
            extra: array_merge($this->extra, $extra)
        );
    }

    // ========== Getters ==========

    public function getToolName(): string
    {
        return $this->toolName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * Get a specific argument value.
     */
    public function getArg(string $key, mixed $default = null): mixed
    {
        return $this->args[$key] ?? $default;
    }

    public function getResult(): ?ToolResult
    {
        return $this->result;
    }

    /**
     * Check whether an execution result has been attached (i.e. this is a post-execution context).
     */
    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    /**
     * Check whether the attached execution result succeeded; false when no result is attached yet.
     */
    public function isSuccessful(): bool
    {
        return $this->result !== null ? $this->result->success : false;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntity(): ?object
    {
        return $this->entity;
    }

    public function getEntityId(): int|string|null
    {
        return $this->entityId;
    }

    /**
     * Check whether entity information (an instance, or at least a type) has been attached.
     */
    public function hasEntity(): bool
    {
        return $this->entity !== null || $this->entityType !== null;
    }

    /**
     * @return array<array{rule: string, effect?: string}>
     */
    public function getAppliedRules(): array
    {
        return $this->appliedRules;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * Get a specific extra value.
     */
    public function getExtraValue(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    // ========== Tool Type Detection ==========

    /**
     * Check if this is a create operation.
     */
    public function isCreateOperation(): bool
    {
        return str_starts_with($this->toolName, 'create_')
            || str_starts_with($this->toolName, 'add_');
    }

    /**
     * Check if this is an update operation.
     */
    public function isUpdateOperation(): bool
    {
        return str_starts_with($this->toolName, 'update_')
            || str_starts_with($this->toolName, 'edit_')
            || str_starts_with($this->toolName, 'modify_');
    }

    /**
     * Check if this is a delete operation.
     */
    public function isDeleteOperation(): bool
    {
        return str_starts_with($this->toolName, 'delete_')
            || str_starts_with($this->toolName, 'remove_');
    }

    /**
     * Check if this is a read/list operation.
     */
    public function isReadOperation(): bool
    {
        return str_starts_with($this->toolName, 'get_')
            || str_starts_with($this->toolName, 'list_')
            || str_starts_with($this->toolName, 'find_')
            || str_starts_with($this->toolName, 'search_');
    }

    /**
     * Check if this is a mutation (create, update, delete).
     */
    public function isMutation(): bool
    {
        return $this->isCreateOperation()
            || $this->isUpdateOperation()
            || $this->isDeleteOperation();
    }

    /**
     * Get the operation type.
     */
    public function getOperationType(): string
    {
        if ($this->isCreateOperation()) {
            return 'create';
        }
        if ($this->isUpdateOperation()) {
            return 'update';
        }
        if ($this->isDeleteOperation()) {
            return 'delete';
        }
        if ($this->isReadOperation()) {
            return 'read';
        }
        return 'unknown';
    }

    /**
     * Get the entity type from tool name (e.g., create_note -> Note).
     */
    public function inferEntityTypeFromTool(): ?string
    {
        $prefixes = ['create_', 'update_', 'delete_', 'get_', 'list_', 'find_', 'add_', 'remove_', 'edit_', 'search_'];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($this->toolName, $prefix)) {
                $name = substr($this->toolName, strlen($prefix));
                // Handle plural forms: notes -> Note, users -> User
                $name = rtrim($name, 's');
                return ucfirst($name);
            }
        }

        return null;
    }
}
