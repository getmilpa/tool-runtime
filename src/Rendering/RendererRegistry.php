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
 * Registry for channel-specific renderers.
 *
 * Manages multiple renderers and selects the appropriate one based on context.
 */
class RendererRegistry
{
    /** @var ChannelRendererInterface[] */
    private array $renderers = [];

    /** @var ChannelRendererInterface|null Default fallback renderer */
    private ?ChannelRendererInterface $defaultRenderer = null;

    /**
     * Register a renderer.
     */
    public function addRenderer(ChannelRendererInterface $renderer): self
    {
        $this->renderers[] = $renderer;
        return $this;
    }

    /**
     * Set the default fallback renderer.
     */
    public function setDefaultRenderer(ChannelRendererInterface $renderer): self
    {
        $this->defaultRenderer = $renderer;
        return $this;
    }

    /**
     * Render a ToolResult using the appropriate renderer for the channel.
     */
    public function render(ToolResult $result, ?ToolContext $ctx = null): mixed
    {
        $channel = $ctx !== null ? $ctx->channel : 'default';

        // Find a renderer that supports this channel
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($channel)) {
                return $renderer->render($result, $ctx);
            }
        }

        // Use default renderer if available
        if ($this->defaultRenderer) {
            return $this->defaultRenderer->render($result, $ctx);
        }

        // Fallback to JSON
        return $result->toJson();
    }

    /**
     * Get a renderer for a specific channel.
     */
    public function getRenderer(string $channel): ?ChannelRendererInterface
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($channel)) {
                return $renderer;
            }
        }
        return $this->defaultRenderer;
    }

    /**
     * Check if a renderer exists for a channel.
     */
    public function hasRenderer(string $channel): bool
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($channel)) {
                return true;
            }
        }
        return $this->defaultRenderer !== null;
    }
}
