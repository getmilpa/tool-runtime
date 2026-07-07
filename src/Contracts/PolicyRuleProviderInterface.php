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

/**
 * Supplies policy rules to the PolicyGate.
 *
 * The Doctrine-backed provider lives in the host; in-memory or config-backed
 * providers are equally valid. Returning null means "no matching rule" and
 * the gate falls back to its channel policies.
 */
interface PolicyRuleProviderInterface
{
    /**
     * Find the policy rule that governs this call, if any.
     *
     * Returning null means "no matching rule" — the gate falls back to its static channel
     * policies rather than treating the absence of a rule as an implicit denial.
     *
     * @param string      $channel   Channel the call originated from (cli, mcp, telegram, web, ...)
     * @param string|null $principal Authenticated principal (user or service), if any
     * @param string      $toolName  Name of the tool being invoked
     * @param bool        $mutating  Whether the tool call mutates state
     */
    public function findMatchingRule(
        string $channel,
        ?string $principal,
        string $toolName,
        bool $mutating
    ): ?PolicyRuleInterface;
}
