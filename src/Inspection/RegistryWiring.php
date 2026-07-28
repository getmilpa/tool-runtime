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
 * Which optional subsystems the registry actually has wired.
 *
 * The inspector has to tell two different silences apart: a step that will not run because the
 * data says so, and a step that cannot run because nothing was ever connected to it. Both look
 * like "nothing happens" at runtime and mean opposite things to whoever is debugging — one is the
 * system working, the other is a hole in the host's wiring.
 */
final readonly class RegistryWiring
{
    public function __construct(
        public bool $hasRateLimiter,
        public bool $hasDispatcher,
        public bool $hasRuleProvider,
    ) {
    }
}
