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
 * Told AFTER a tool call, with what the tool answered.
 *
 * A {@see ToolCallGate} decides before; it never sees the outcome. Whoever keeps a record of what was
 * executed — a session ledger, an audit — implements this and is told once per call, success or failure,
 * with the rendered result and whether it succeeded.
 */
interface ToolCallRecorder
{
    /**
     * Told once per call: the tool, its arguments, the rendered result and whether it succeeded.
     *
     * @param array<string, mixed> $arguments
     */
    public function recorded(string $tool, array $arguments, string $result, bool $ok): void;
}
