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

namespace Milpa\ToolRuntime\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\ToolDefinition;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolScanner;
use Milpa\ValueObjects\Tooling\ToolOptions;

// Tool service exercising the new #[Tool(version:, outputSchema:)] support (D7).
class VersionedToolService
{
    #[Tool(
        'hero_generate',
        'Generate a hero image',
        version: '2.0.0',
        outputSchema: ['type' => 'object', 'properties' => ['image_id' => ['type' => 'string']]]
    )]
    public function heroGenerate(string $brand): array
    {
        return ['image_id' => 'art_1', 'brand' => $brand];
    }

    #[Tool('plain_tool', 'A tool without version/outputSchema')]
    public function plainTool(): array
    {
        return [];
    }
}

/**
 * B1 / D7 — ToolDefinition gains optional `version` + `outputSchema`
 * (additive, forward-compatible with STATIONS §8.4 ToolDescriptor).
 */
class ToolVersionOutputSchemaTest extends TestCase
{
    private ToolRegistry $registry;
    private ToolScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $logger = $this->createMock(LoggerInterface::class);
        $this->registry = new ToolRegistry($logger);
        $this->scanner = new ToolScanner($this->registry);
    }

    public function testToolDefinitionDefaultsVersionAndOutputSchemaToNull(): void
    {
        $def = new ToolDefinition('t', 'desc', [], fn () => null);

        $this->assertNull($def->version);
        $this->assertNull($def->outputSchema);
    }

    public function testRegisterStoresVersionAndOutputSchemaOnDefinition(): void
    {
        $this->registry->register('t', 'desc', ['type' => 'object'], fn () => null, ToolOptions::fromArray([
            'version' => '2.0.0',
            'outputSchema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
        ]));

        $def = $this->registry->getDefinition('t');

        $this->assertSame('2.0.0', $def->version);
        $this->assertSame(['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]], $def->outputSchema);
    }

    public function testScannerPopulatesVersionAndOutputSchemaFromToolAttribute(): void
    {
        $this->scanner->scan(new VersionedToolService());

        $def = $this->registry->getDefinition('hero_generate');

        $this->assertSame('2.0.0', $def->version);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['image_id' => ['type' => 'string']]],
            $def->outputSchema
        );
    }

    public function testScannedPlainToolHasNullVersionAndOutputSchema(): void
    {
        $this->scanner->scan(new VersionedToolService());

        $def = $this->registry->getDefinition('plain_tool');

        $this->assertNull($def->version);
        $this->assertNull($def->outputSchema);
    }

    public function testGetToolsSurfacesOutputSchemaWhenPresent(): void
    {
        $this->scanner->scan(new VersionedToolService());

        $tools = $this->registry->getTools();
        $hero = array_values(array_filter($tools, fn ($t) => $t['name'] === 'hero_generate'))[0];

        $this->assertArrayHasKey('outputSchema', $hero);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['image_id' => ['type' => 'string']]],
            $hero['outputSchema']
        );
    }

    public function testGetToolsOmitsOutputSchemaWhenAbsent(): void
    {
        $this->scanner->scan(new VersionedToolService());

        $tools = $this->registry->getTools();
        $plain = array_values(array_filter($tools, fn ($t) => $t['name'] === 'plain_tool'))[0];

        $this->assertArrayNotHasKey('outputSchema', $plain);
    }
}
