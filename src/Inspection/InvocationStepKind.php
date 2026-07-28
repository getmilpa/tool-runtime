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

/**
 * The stations an invocation can pass through, in the order the pipeline runs them.
 *
 * The order is the contract: authorize after validate, because refusing malformed input is not an
 * authorization decision; clamp between them, because a value has to be inside its bounds before a
 * policy can be asked about it.
 */
enum InvocationStepKind: string
{
    case Resolve = 'resolve';
    case Validate = 'validate';
    case Clamp = 'clamp';
    case Authorize = 'authorize';
    case RateLimit = 'rate_limit';
    case PlanMode = 'plan_mode';
    case Confirm = 'confirm';
    case EmitExecuting = 'emit_executing';
    case Execute = 'execute';
    case ContainException = 'contain_exception';
    case Audit = 'audit';

    /**
     * El orden del MODELO inspeccionable (ADR#13 P13.1) — NO la fuente ejecutable. La fuente
     * ejecutable es {@see \Milpa\ToolRuntime\ToolRegistry::call()}; este orden es su espejo,
     * protegido por el drift-guard ({@see \Milpa\ToolRuntime\Tests\Inspection\InvocationPipelineDriftTest})
     * que muerde cualquier divergencia. Mientras call() no CONSUMA esta secuencia, son dos
     * representaciones vigiladas, no una sola fuente.
     *
     * @return list<self>
     */
    public static function inspectionOrder(): array
    {
        return [
            self::Resolve, self::Validate, self::Clamp, self::Authorize, self::RateLimit,
            self::PlanMode, self::Confirm, self::EmitExecuting, self::Execute,
            self::ContainException, self::Audit,
        ];
    }
}
