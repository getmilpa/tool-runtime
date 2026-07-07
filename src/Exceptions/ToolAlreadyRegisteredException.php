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

namespace Milpa\ToolRuntime\Exceptions;

/**
 * Exception thrown when registering a tool that already exists.
 */
class ToolAlreadyRegisteredException extends \Exception
{
    public function __construct(string $name)
    {
        parent::__construct("Tool already registered: {$name}");
    }
}
