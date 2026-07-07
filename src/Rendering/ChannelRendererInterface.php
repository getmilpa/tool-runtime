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

namespace Milpa\ToolRuntime\Rendering;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;

/**
 * Interface for channel-specific renderers.
 *
 * Each channel (Telegram, CLI, Web) has its own renderer that transforms
 * ToolResult into the appropriate format for that channel.
 */
interface ChannelRendererInterface
{
    /**
     * Check if this renderer supports the given channel.
     */
    public function supports(string $channel): bool;

    /**
     * Render the ToolResult for the specific channel.
     *
     * @param ToolResult       $result The structured result to render
     * @param ToolContext|null $ctx    The execution context
     *
     * @return mixed Channel-specific output (string, array, etc.)
     */
    public function render(ToolResult $result, ?ToolContext $ctx = null): mixed;
}
