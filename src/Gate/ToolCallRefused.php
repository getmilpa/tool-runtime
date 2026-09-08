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
 * A {@see ToolCallGate} refused this call.
 *
 * Its own type so a caller can catch it apart from a tool's failure: a refusal ends the turn, a failure is
 * reported to whoever called. `optionRemoved` says whether the refused tool was an option the caller had
 * already taken off the table — a model that insists on a removed option is a different fact from one that
 * asks for something it was never offered.
 */
class ToolCallRefused extends \RuntimeException
{
    /** The gate's reason, and whether the refused tool was an option already taken off the table. */
    public function __construct(string $message, public readonly bool $optionRemoved = false)
    {
        parent::__construct($message);
    }
}
