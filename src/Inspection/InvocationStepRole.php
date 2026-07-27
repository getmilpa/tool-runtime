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

namespace Milpa\ToolRuntime\Inspection;

enum InvocationStepRole: string
{
    case Guard = 'guard';           // Validate, Authorize — puede short-circuit con un error code
    case Transform = 'transform';   // Clamp — reescribe args
    case Branch = 'branch';         // PlanMode, Confirm — desvían a un payload success/confirmation
    case Hook = 'hook';             // EmitExecuting — el anchor de intercepción
    case Execution = 'execution';   // Execute — corre el callback real
    case Boundary = 'boundary';     // ContainException — ENVUELVE Execute (no ocurre "después")
    case Outcome = 'outcome';       // Audit — cubre terminal paths (cobertura real, no todos)
}
