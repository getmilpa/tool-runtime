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
use Milpa\ToolRuntime\Contracts\ResultBudget;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * Tool calls through a gate: consult, execute through the registry, record.
 *
 * This is the one place a tool is called on behalf of an actor that must be governed — a model, a recipe, a
 * sequence. Every caller extends it or holds one: a model gateway adds what it removed from the table, a
 * governed door adds the consent it collected (greenhouse decisions/0225). Extensions separate three facts:
 * {@see self::hidden()} controls catalogue visibility, {@see self::withdrawn()} forbids execution, and
 * {@see self::optionRemoved()} marks a gate refusal against historical withdrawal. Nothing here knows what a session or a model is.
 */
class GatedToolCalls
{
    private ?ToolContext $context = null;

    private ?ResultBudget $resultBudget = null;

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
     * Scope a transport budget to one synchronous call, preserving subclass dispatch and gates.
     * The overlay is applied after an extension has supplied the actual caller's context.
     *
     * @param array<string, mixed> $args
     */
    final public function callToolWithBudget(string $name, array $args, ResultBudget $budget): mixed
    {
        $previous = $this->resultBudget;
        $this->resultBudget = $budget;
        try {
            return $this->callTool($name, $args);
        } finally {
            $this->resultBudget = $previous;
        }
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
     * Call one tool: scopes, active withdrawal, gate, registry, then recorder.
     *
     * @param array<string, mixed> $args
     *
     * @throws ToolCallRefused when an active withdrawal or the gate refuses
     * @throws \Exception      when the tool itself failed, with the tool's own error
     */
    public function callTool(string $name, array $args): mixed
    {
        $context = $this->context;
        if ($this->resultBudget !== null) {
            $context = ($context ?? ToolContext::cli())->withResultBudget($this->resultBudget);
        }
        // Scope is authority, not consent. An out-of-scope call must not open a session
        // question whose answer cannot authorize it (greenhouse decisions/0314).
        // Unknown tools retain the existing resolution/gate path; no contract is invented.
        $definition = $this->registry->getDefinition($name);
        if ($definition !== null) {
            $scope = $this->registry->getPolicyGate()->authorizeCall(
                $context ?? ToolContext::cli(),
                $definition,
                $args,
            );
            if (!$scope->allowed) {
                $error = (string) $scope->reason;
                $this->recorder?->recorded($name, $args, $error, false);

                // Preserve the registry's scope-failure shape; this is not a session pause.
                throw new \Exception($error);
            }
        }

        // Visibility can be temporary and history can be record-only. Only the current
        // withdrawal projection forbids execution (greenhouse decisions/0362).
        if (\in_array($name, $this->withdrawn(), true)) {
            $reason = "Tool '{$name}' has been withdrawn from this session.";
            $this->recorder?->recorded($name, $args, $reason, false);

            throw new ToolCallRefused($reason, optionRemoved: true);
        }

        if ($this->gate !== null) {
            $reason = $this->gate->refuse($name, $args);
            if ($reason !== null) {
                throw new ToolCallRefused($reason, optionRemoved: $this->optionRemoved($name));
            }
        }

        $result = $this->registry->call($name, $args, $context);
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

    /**
     * Names currently forbidden from execution, independent of visibility and removal history.
     *
     * @return list<string>
     */
    protected function withdrawn(): array
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
