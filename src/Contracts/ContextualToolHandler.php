<?php

/**
 * This file is part of Milpa tool-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Contracts;

/** An explicit opt-in to receive the verified caller; legacy callbacks still receive one argument. */
interface ContextualToolHandler
{
    /** @param array<string, mixed> $arguments */
    public function __invoke(array $arguments, ToolContext $context): mixed;
}
