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
 * A single authorization policy rule, as consumed by the PolicyGate.
 *
 * Backing storage is the host's concern (the Milpa monorepo implements it
 * with a Doctrine entity); the gate only reads these four accessors.
 */
interface PolicyRuleInterface
{
    /** Persistent identifier of the rule, or null when it has not been persisted (e.g. an in-memory rule). */
    public function getId(): ?int;

    /** Either "allow" or "deny". */
    public function getEffect(): string;

    /**
     * Scopes the caller must hold to satisfy this rule.
     *
     * @return list<string>|null Any-of set of required scopes, or null when the rule imposes no scope requirement.
     */
    public function getRequiresScopes(): ?array;

    /** Human-readable explanation surfaced back to the caller when this rule denies the call. */
    public function getDescription(): ?string;
}
