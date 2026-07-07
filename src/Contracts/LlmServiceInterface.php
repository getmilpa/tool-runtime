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

namespace Milpa\ToolRuntime\Contracts;

/**
 * Interface for LLM (Large Language Model) services.
 *
 * Plugins can provide this interface to enable AI capabilities.
 * Other plugins can require this interface to consume AI services.
 */
interface LlmServiceInterface
{
    /**
     * Generate a response from the LLM.
     *
     * @param string                     $prompt   The user prompt
     * @param list<array<string, mixed>> $tools    Available tools in MCP format
     * @param list<array<string, mixed>> $messages Conversation history
     *
     * @return array<string, mixed> The LLM response (OpenAI-compatible format)
     */
    public function generateResponse(string $prompt, array $tools = [], array $messages = [], int $maxTokens = 4096): array;
}
