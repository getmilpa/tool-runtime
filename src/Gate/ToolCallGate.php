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

namespace Milpa\ToolRuntime\Gate;

/**
 * Consulted BEFORE every tool call; it may refuse the call.
 *
 * ── WHY IT LIVES HERE, EMPTY OF POLICY ────────────────────────────────────────────────────────
 *
 * The runtime that calls tools does not know — and has no reason to — what a session, a permission, an
 * autonomy mode or a signature is. That lives in the packages that govern (milpa/agent, milpa/app-runtime).
 * A model gateway is ONE caller of tools; a human applying a recipe from a terminal is another. Both ask
 * the same question before each call, so the question is asked here, where every caller already depends
 * (greenhouse decisions/0225). Whoever wants to decide implements this; with no gate wired, calls run
 * exactly as they ran — the absence of a policy cannot be a new policy.
 *
 * ── REFUSING STOPS THE LOOP ───────────────────────────────────────────────────────────────────
 *
 * A refusal is NOT a tool error. Handed back to a model as text, it would read «you cannot do that» and try
 * something else — exactly what a gate must not invite. {@see GatedToolCalls::callTool()} throws
 * {@see ToolCallRefused}, and a caller catches it apart, BEFORE any generic catch, and ends the turn.
 */
interface ToolCallGate
{
    /**
     * The reason this call does not proceed, or `null` when it does.
     *
     * A REASON and not a boolean, like the rest of this family: whoever refuses knows why, and whoever
     * receives the refusal needs that sentence to do something with it.
     *
     * @param array<string, mixed> $arguments
     */
    public function refuse(string $tool, array $arguments): ?string;
}
