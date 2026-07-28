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
 * How live a step is for this call — the distinction the whole inspector exists to make.
 *
 * `Dormant` and `Skipped` both produce no effect and mean opposite things: dormant is a rule that
 * exists and cannot fire given what is already known, skipped is a subsystem that was never wired.
 * Collapsing them into "off" is how a missing rate limiter reads as a rate limiter with nothing to
 * do. `Conditional` is the honest answer when only runtime data can settle it.
 */
enum StepPresence: string
{
    case Active = 'active';           // corre en esta invocación/modelo
    case Conditional = 'conditional'; // puede correr según datos conocidos SOLO en runtime
    case Dormant = 'dormant';         // la regla existe, pero hechos estáticos actuales hacen IMPOSIBLE que dispare
    case Skipped = 'skipped';         // el subsistema no aplica o no está conectado (wiring ausente)
}
