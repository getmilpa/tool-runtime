<?php

/*
 * This file is part of milpa/tool-runtime.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the JSON Schema validity of the transport-facing tool catalog.
 *
 * A tool with zero parameters stores `properties` as an empty PHP array, and
 * `json_encode()` turns an empty PHP array into `[]` — but JSON Schema requires
 * `properties` to be an object (`{}`). A strict MCP host rejects `[]`, so the
 * summaries boundary must normalize empty properties to an object before
 * consumers serialize the catalog.
 */
final class ToolSummariesJsonSchemaTest extends TestCase
{
    public function testParameterlessToolSerializesPropertiesAsJsonObject(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register(
            'no_params',
            'A tool with zero parameters',
            ['type' => 'object', 'properties' => []],
            static fn (): array => ['ok' => true],
        );

        $json = json_encode($registry->getToolSummaries(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"properties":{}', $json);
        $this->assertStringNotContainsString('"properties":[]', $json);
    }

    public function testToolWithParametersKeepsItsPropertiesIntact(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register(
            'with_params',
            'A tool with one parameter',
            [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ],
            static fn (): array => ['ok' => true],
        );

        $summaries = $registry->getToolSummaries();

        $this->assertSame(['id' => ['type' => 'integer']], $summaries[0]['inputSchema']['properties']);
        $json = json_encode($summaries, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"properties":{"id":{"type":"integer"}}', $json);
    }
}
