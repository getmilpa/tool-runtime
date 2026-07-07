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

namespace Milpa\ToolRuntime;

use Milpa\ToolRuntime\Contracts\IntelligenceContext;
use Milpa\ToolRuntime\Contracts\ToolIntelligence;
use Milpa\ToolRuntime\Contracts\IntelligenceProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates intelligence from multiple providers.
 *
 * Manages the lifecycle of Intelligence Providers:
 * - Provider registration
 * - Priority-based sorting
 * - Pre/Post execution gathering
 * - Result merging
 *
 * Usage:
 * ```php
 * $aggregator = new IntelligenceAggregator($logger);
 * $aggregator->registerProvider($myIntelligenceProvider); // any IntelligenceProviderInterface
 *
 * // Pre-execution (before tool runs)
 * $preIntelligence = $aggregator->gatherPre($context);
 *
 * // Post-execution (after tool runs)
 * $postIntelligence = $aggregator->gatherPost($context);
 *
 * // Merge all intelligence
 * $fullIntelligence = $preIntelligence->merge($postIntelligence);
 * ```
 *
 * @package Milpa\ToolRuntime
 */
class IntelligenceAggregator
{
    /**
     * @var array<IntelligenceProviderInterface>
     */
    private array $providers = [];

    /**
     * @var bool Whether providers need sorting
     */
    private bool $needsSort = false;

    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    // ========== Provider Registration ==========

    /**
     * Register an intelligence provider.
     *
     * @param IntelligenceProviderInterface $provider The provider to register
     *
     * @return self For chaining
     */
    public function registerProvider(IntelligenceProviderInterface $provider): self
    {
        $this->providers[] = $provider;
        $this->needsSort = true;

        $this->log("Registered intelligence provider: {$provider->getName()} (priority: {$provider->getPriority()})");

        return $this;
    }

    /**
     * Register multiple providers at once.
     *
     * @param array<IntelligenceProviderInterface> $providers
     *
     * @return self For chaining
     */
    public function registerProviders(array $providers): self
    {
        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
        return $this;
    }

    /**
     * Remove a provider by name.
     *
     * @param string $name Provider name to remove
     *
     * @return bool True if provider was found and removed
     */
    public function removeProvider(string $name): bool
    {
        foreach ($this->providers as $key => $provider) {
            if ($provider->getName() === $name) {
                unset($this->providers[$key]);
                $this->providers = array_values($this->providers);
                $this->log("Removed intelligence provider: {$name}");
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a provider is registered.
     *
     * @param string $name Provider name to check
     */
    public function hasProvider(string $name): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->getName() === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all registered provider names.
     *
     * @return array<string>
     */
    public function getProviderNames(): array
    {
        return array_map(fn ($p) => $p->getName(), $this->providers);
    }

    // ========== Intelligence Gathering ==========

    /**
     * Gather pre-execution intelligence.
     *
     * Called before the tool executes. Returns warnings and would_block info.
     *
     * @param IntelligenceContext $context Execution context
     *
     * @return ToolIntelligence Aggregated pre-execution intelligence
     */
    public function gatherPre(IntelligenceContext $context): ToolIntelligence
    {
        return $this->gather($context, 'pre');
    }

    /**
     * Gather post-execution intelligence.
     *
     * Called after the tool executes. Returns suggestions, next_actions, context.
     *
     * @param IntelligenceContext $context Execution context (with result)
     *
     * @return ToolIntelligence Aggregated post-execution intelligence
     */
    public function gatherPost(IntelligenceContext $context): ToolIntelligence
    {
        return $this->gather($context, 'post');
    }

    /**
     * Gather intelligence from all applicable providers.
     *
     * @param IntelligenceContext $context Execution context
     * @param string              $phase   "pre", "post", or "both"
     *
     * @return ToolIntelligence Aggregated intelligence
     */
    public function gather(IntelligenceContext $context, string $phase = 'both'): ToolIntelligence
    {
        $this->sortProviders();

        $result = ToolIntelligence::empty();
        $toolName = $context->getToolName();

        foreach ($this->providers as $provider) {
            // Check phase match
            $providerPhase = $provider->getPhase();
            if ($phase !== 'both' && $providerPhase !== 'both' && $providerPhase !== $phase) {
                continue;
            }

            // Check if provider supports this context
            if (!$provider->supports($context)) {
                continue;
            }

            try {
                $start = microtime(true);
                $intelligence = $provider->provide($context);
                $duration = round((microtime(true) - $start) * 1000, 2);

                $result = $result->merge($intelligence);

                $this->log(
                    "Provider {$provider->getName()} generated intelligence for {$toolName} ({$duration}ms)",
                    ['phase' => $phase]
                );
            } catch (\Throwable $e) {
                $this->log(
                    "Provider {$provider->getName()} failed: {$e->getMessage()}",
                    ['error' => true, 'tool' => $toolName]
                );
                // Continue with other providers - don't let one failure break everything
            }
        }

        return $result;
    }

    /**
     * Gather all intelligence (pre + post merged).
     *
     * Convenience method that gathers both phases.
     *
     * @param IntelligenceContext $preContext  Pre-execution context
     * @param IntelligenceContext $postContext Post-execution context
     *
     * @return ToolIntelligence Complete aggregated intelligence
     */
    public function gatherAll(
        IntelligenceContext $preContext,
        IntelligenceContext $postContext
    ): ToolIntelligence {
        $preIntelligence = $this->gatherPre($preContext);
        $postIntelligence = $this->gatherPost($postContext);

        return $preIntelligence->merge($postIntelligence);
    }

    // ========== Helper Methods ==========

    /**
     * Build an intelligent result from a ToolResult.
     *
     * @param ToolResult          $result  Original result
     * @param IntelligenceContext $context Execution context
     * @param string              $action  Tool/action name
     * @param string|null         $attempt Human-readable attempt description (auto-generated if null)
     *
     * @return IntelligentToolResult Result with intelligence
     */
    public function buildIntelligentResult(
        ToolResult $result,
        IntelligenceContext $context,
        string $action,
        ?string $attempt = null
    ): IntelligentToolResult {
        // Gather post-execution intelligence
        $contextWithResult = $context->withResult($result);
        $intelligence = $this->gatherPost($contextWithResult);

        // Auto-generate attempt description if not provided
        $attempt = $attempt ?? $this->generateAttemptDescription($action, $context->getArgs());

        return IntelligentToolResult::fromToolResult(
            $result,
            $intelligence,
            $action,
            $attempt
        );
    }

    /**
     * Generate a human-readable attempt description.
     *
     * @param string               $action Tool/action name
     * @param array<string, mixed> $args   Arguments
     *
     * @return string Human-readable description
     */
    public function generateAttemptDescription(string $action, array $args): string
    {
        // Extract key identifiers
        $id = $args['id'] ?? null;
        $name = $args['nombre'] ?? $args['name'] ?? null;

        // Build description based on action type
        if (str_starts_with($action, 'create_')) {
            $entity = ucfirst(str_replace('create_', '', $action));
            if ($name) {
                return "Crear {$entity} '{$name}'";
            }
            return "Crear {$entity}";
        }

        if (str_starts_with($action, 'update_') || str_starts_with($action, 'edit_')) {
            $entity = ucfirst(preg_replace('/^(update_|edit_)/', '', $action));
            if ($id) {
                return "Actualizar {$entity} #{$id}";
            }
            return "Actualizar {$entity}";
        }

        if (str_starts_with($action, 'delete_') || str_starts_with($action, 'remove_')) {
            $entity = ucfirst(preg_replace('/^(delete_|remove_)/', '', $action));
            if ($id) {
                return "Eliminar {$entity} #{$id}";
            }
            return "Eliminar {$entity}";
        }

        if (str_starts_with($action, 'get_')) {
            $entity = ucfirst(str_replace('get_', '', $action));
            if ($id) {
                return "Obtener {$entity} #{$id}";
            }
            return "Obtener {$entity}";
        }

        if (str_starts_with($action, 'list_')) {
            $entity = ucfirst(str_replace('list_', '', $action));
            return "Listar {$entity}";
        }

        if (str_starts_with($action, 'find_') || str_starts_with($action, 'search_')) {
            $entity = ucfirst(preg_replace('/^(find_|search_)/', '', $action));
            return "Buscar {$entity}";
        }

        // Generic fallback
        return "Ejecutar {$action}";
    }

    /**
     * Get count of registered providers.
     */
    public function getProviderCount(): int
    {
        return count($this->providers);
    }

    /**
     * Clear all registered providers.
     */
    public function clearProviders(): void
    {
        $this->providers = [];
        $this->needsSort = false;
        $this->log("Cleared all intelligence providers");
    }

    // ========== Internal ==========

    /**
     * Sort providers by priority (higher first).
     */
    private function sortProviders(): void
    {
        if (!$this->needsSort) {
            return;
        }

        usort($this->providers, function (
            IntelligenceProviderInterface $a,
            IntelligenceProviderInterface $b
        ): int {
            return $b->getPriority() <=> $a->getPriority();
        });

        $this->needsSort = false;
    }

    /**
     * Log a message if logger is available.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        // Format context into message since LoggerInterface only accepts string
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';

        if ($context['error'] ?? false) {
            $this->logger->warning("[ITP] {$message}{$contextStr}");
        } else {
            $this->logger->debug("[ITP] {$message}{$contextStr}");
        }
    }
}
