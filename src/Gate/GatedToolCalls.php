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

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * Tool calls through a gate: consult, execute through the registry, record.
 *
 * This is the one place a tool is called on behalf of an actor that must be governed — a model, a recipe, a
 * sequence. Every caller extends it or holds one: a model gateway adds what it removed from the table, a
 * governed door adds the consent it collected (greenhouse decisions/0225). Two hooks are all an extension
 * needs: {@see self::hidden()} for names that leave the catalogue, {@see self::optionRemoved()} for a
 * refusal that names an option already taken away. Nothing here knows what a session or a model is.
 */
class GatedToolCalls
{
    private ?ToolContext $context = null;

    /** Calls through `$registry`, asking `$gate` before each one and telling `$recorder` after — both optional. */
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ?ToolCallGate $gate = null,
        private readonly ?ToolCallRecorder $recorder = null,
    ) {
    }

    /** The context every call from now on carries to the registry (who, on which channel). */
    public function setContext(ToolContext $context): void
    {
        $this->context = $context;
    }

    /**
     * The registry's tool summaries, minus what {@see self::hidden()} takes away.
     *
     * @return list<array<string, mixed>>
     */
    public function getToolSummaries(): array
    {
        $catalogue = $this->registry->getToolSummaries();
        $hidden = $this->hidden();
        if ($hidden === []) {
            return $catalogue;
        }

        return array_values(array_filter(
            $catalogue,
            static fn (array $tool): bool => !\in_array($tool['name'], $hidden, true),
        ));
    }

    /**
     * Call one tool: the gate first, then the registry, then the recorder.
     *
     * @param array<string, mixed> $args
     *
     * @throws ToolCallRefused when the gate refuses — never handed back as a tool error
     * @throws \Exception      when the tool itself failed, with the tool's own error
     */
    public function callTool(string $name, array $args): mixed
    {
        if ($this->gate !== null) {
            $reason = $this->gate->refuse($name, $args);
            if ($reason !== null) {
                throw new ToolCallRefused($reason, optionRemoved: $this->optionRemoved($name));
            }
        }

        $result = $this->registry->call($name, $args, $this->context);
        if ($result->success) {
            $this->recorder?->recorded($name, $args, $this->rendered($result->data), true);

            return $result->data;
        }

        $error = $result->error ?? 'Tool execution failed';
        $this->recorder?->recorded($name, $args, $error, false);

        throw new \Exception($error);
    }

    /**
     * Names that do not leave the catalogue — none here; an extension that took options off the table says which.
     *
     * @return list<string>
     */
    protected function hidden(): array
    {
        return [];
    }

    /** Whether a refusal of `$tool` names an option already taken away — never here. */
    protected function optionRemoved(string $tool): bool
    {
        return false;
    }

    /**
     * What a tool returned, as text — a string as is, anything else as JSON, never as «Array».
     *
     * Byte-for-byte what the model gateway recorded before the move (greenhouse decisions/0225): `null` is
     * `null`, `0` is `0`, and what JSON cannot encode is the empty string — a recorder that read one shape
     * for years must not be handed another because the class changed package.
     */
    protected function rendered(mixed $data): string
    {
        if (\is_string($data)) {
            return $data;
        }

        return json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '';
    }
}
