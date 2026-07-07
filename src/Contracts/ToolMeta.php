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
 * Metadata about a tool execution.
 */
class ToolMeta
{
    public function __construct(
        public readonly string $tool,
        public readonly int $took_ms,
        public readonly string $request_id,
        public readonly ?string $channel = null,
        public readonly ?string $principal = null
    ) {
    }

    /**
     * Serialize this metadata to a plain array for logging or transport.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'took_ms' => $this->took_ms,
            'request_id' => $this->request_id,
            'channel' => $this->channel,
            'principal' => $this->principal,
        ];
    }

    /**
     * Generate a unique request ID.
     */
    public static function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
