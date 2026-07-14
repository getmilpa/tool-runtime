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

namespace Milpa\ToolRuntime\Rendering;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolResult;

/**
 * Default fallback renderer.
 *
 * Returns plain text or JSON when no channel-specific renderer is available.
 */
class DefaultRenderer implements ChannelRendererInterface
{
    /**
     * Check whether this renderer handles the given channel; always true since it is the
     * last-resort fallback used when no channel-specific renderer claims the channel.
     */
    public function supports(string $channel): bool
    {
        // Supports all channels as fallback
        return true;
    }

    /**
     * Render a ToolResult as plain text, appending pagination info or a block suggestion
     * when present in the result's metadata.
     */
    public function render(ToolResult $result, ?ToolContext $ctx = null): string
    {
        if ($result->success) {
            $output = $result->message ?? 'Operation completed successfully.';

            if ($result->data && is_array($result->data)) {
                if ($result->getType() === 'list') {
                    $output .= "\n\n" . $this->formatList($result->data);
                } else {
                    $output .= "\n\n" . $this->formatDetail($result->data);
                }
            }

            // Add pagination info if present
            $pagination = $result->getPagination();
            if ($pagination) {
                $output .= sprintf(
                    "\n\nPage %d of %d (%d items total)",
                    $pagination['page'],
                    $pagination['total_pages'],
                    $pagination['total_items']
                );
            }

            return $output;
        }

        // Error case
        $output = "Error: " . ($result->error ?? 'Unknown error');

        if ($result->isBlocked() && isset($result->meta['suggestion'])) {
            $output .= "\n\nSuggestion: " . $result->meta['suggestion'];
        }

        return $output;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function formatList(array $items): string
    {
        $lines = [];
        foreach ($items as $i => $item) {
            $id = $item['id'] ?? ($i + 1);
            $name = $item['nombre'] ?? $item['name'] ?? $item['title'] ?? 'N/A';
            $lines[] = "#{$id}: {$name}";
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatDetail(array $item): string
    {
        $lines = [];
        foreach ($item as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', $key));
            $formattedValue = is_array($value) ? json_encode($value) : (string) $value;
            $lines[] = "- {$label}: {$formattedValue}";
        }
        return implode("\n", $lines);
    }
}
