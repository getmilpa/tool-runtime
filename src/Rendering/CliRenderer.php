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
 * Renderer for CLI channel.
 *
 * Transforms ToolResult into CLI-friendly output using Symfony Console formatting.
 */
class CliRenderer implements ChannelRendererInterface
{
    /**
     * Check whether this renderer handles the given channel (CLI only).
     */
    public function supports(string $channel): bool
    {
        return $channel === 'cli';
    }

    /**
     * Render a ToolResult as Symfony-Console-formatted text, dispatching by result type
     * (list, detail, confirmation, blocked, error, or a generic fallback).
     */
    public function render(ToolResult $result, ?ToolContext $ctx = null): string
    {
        $type = $result->getType();

        return match ($type) {
            'list' => $this->renderList($result),
            'detail' => $this->renderDetail($result),
            'confirmation' => $this->renderConfirmation($result),
            'blocked' => $this->renderBlocked($result),
            'error' => $this->renderError($result),
            default => $this->renderGeneric($result),
        };
    }

    // ========== Type-Specific Renderers ==========

    private function renderList(ToolResult $result): string
    {
        $items = $result->data ?? [];
        $pagination = $result->getPagination();
        $message = $result->message ?? 'Results';

        $output = "<info>{$message}</info>\n";
        $output .= str_repeat('-', 50) . "\n";

        if (empty($items)) {
            $output .= "<comment>No results found.</comment>\n";
        } else {
            // Build table-like output
            foreach ($items as $i => $item) {
                $id = $item['id'] ?? ($i + 1);
                $name = $item['nombre'] ?? $item['name'] ?? $item['title'] ?? 'N/A';
                $status = isset($item['available']) ? ($item['available'] ? '[OK]' : '[--]') : '';

                $output .= sprintf("  %s #%-4d %s\n", $status, $id, $name);
            }
        }

        if ($pagination) {
            $output .= str_repeat('-', 50) . "\n";
            $output .= sprintf(
                "<comment>Page %d/%d (%d total items)</comment>\n",
                $pagination['page'],
                $pagination['total_pages'],
                $pagination['total_items']
            );
        }

        return $output;
    }

    private function renderDetail(ToolResult $result): string
    {
        $item = $result->data ?? [];
        $entity = $result->meta['entity'] ?? 'Item';
        $message = $result->message ?? '';

        $output = "<info>{$entity}</info>";
        if ($message) {
            $output .= " - {$message}";
        }
        $output .= "\n" . str_repeat('=', 40) . "\n";

        foreach ($item as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', (string) $key));
            $formattedValue = $this->formatValue($value);
            $output .= sprintf("  <comment>%-15s</comment> %s\n", $label . ':', $formattedValue);
        }

        // Show actions if any
        $actions = $result->getActions();
        if (!empty($actions)) {
            $output .= "\n<info>Available actions:</info>\n";
            foreach ($actions as $action) {
                $output .= "  - {$action['label']}: {$action['action']}\n";
            }
        }

        return $output;
    }

    private function renderConfirmation(ToolResult $result): string
    {
        $message = $result->message ?? 'Confirmation required';

        $output = "<fg=yellow;options=bold>⚠ CONFIRMATION REQUIRED</>\n";
        $output .= str_repeat('-', 40) . "\n";
        $output .= "{$message}\n";

        if ($result->data && is_array($result->data)) {
            $output .= "\n";
            foreach ($result->data as $key => $value) {
                $label = ucfirst(str_replace('_', ' ', (string) $key));
                $output .= "  {$label}: {$this->formatValue($value)}\n";
            }
        }

        $output .= "\n<comment>Type 'yes' to confirm or 'no' to cancel.</comment>\n";

        return $output;
    }

    private function renderBlocked(ToolResult $result): string
    {
        $error = $result->error ?? 'Operation blocked';
        $suggestion = $result->meta['suggestion'] ?? null;

        $output = "<fg=red;options=bold>⛔ OPERATION BLOCKED</>\n";
        $output .= str_repeat('-', 40) . "\n";
        $output .= "<error>{$error}</error>\n";

        if ($suggestion) {
            $output .= "\n<info>💡 Suggestion:</info> {$suggestion}\n";
        }

        return $output;
    }

    private function renderError(ToolResult $result): string
    {
        $error = $result->error ?? 'Unknown error';

        return "<error>✗ Error: {$error}</error>\n";
    }

    private function renderGeneric(ToolResult $result): string
    {
        $prefix = $result->success ? '<info>✓</info>' : '<error>✗</error>';
        $content = $result->message ?? $result->error ?? '';

        $output = "{$prefix} {$content}\n";

        if ($result->data && is_array($result->data)) {
            foreach ($result->data as $key => $value) {
                $label = ucfirst(str_replace('_', ' ', (string) $key));
                $output .= "  {$label}: {$this->formatValue($value)}\n";
            }
        }

        return $output;
    }

    // ========== Helpers ==========

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '<comment>N/A</comment>';
        }
        if (is_bool($value)) {
            return $value ? '<info>Yes</info>' : '<comment>No</comment>';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }
}
