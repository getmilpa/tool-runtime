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

use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolDefinition;

/** Host-owned restrictions on a concrete call, independent of its consent. */
interface CallPolicy
{
    /**
     * Restrict a concrete call after its declared scopes have passed.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorize(ToolContext $context, ToolDefinition $tool, array $arguments): AuthorizationResult;
}
