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

namespace Milpa\ToolRuntime\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolScanner;
use Milpa\ToolRuntime\Attributes\Tool;
use Milpa\ToolRuntime\Attributes\Param;
use Psr\Log\LoggerInterface;

// Test service class with Tool attributes
class TestToolService
{
    #[Tool('list_items', 'List all items')]
    public function listItems(int $page = 1, int $limit = 20): array
    {
        return ['items' => [], 'page' => $page, 'limit' => $limit];
    }

    #[Tool('create_item', 'Create a new item', scopes: ['items:write'])]
    public function createItem(string $name, #[Param('Item description', required: true)] string $description): array
    {
        return ['id' => 1, 'name' => $name, 'description' => $description];
    }

    #[Tool('delete_item', 'Delete an item', scopes: ['items:delete'], confirm: true)]
    public function deleteItem(int $id): bool
    {
        return true;
    }

    #[Tool('search_items', 'Search items with filters')]
    public function searchItems(
        #[Param('Search query')]
        string $query,
        #[Param('Sort order', enum: ['asc', 'desc'])]
        string $sort = 'asc',
        #[Param('Result limit', clamp: [1, 100])]
        int $limit = 20
    ): array {
        return ['query' => $query, 'sort' => $sort, 'limit' => $limit];
    }

    // This method should NOT be scanned (no Tool attribute)
    public function helperMethod(): void
    {
    }

    // Private method should NOT be scanned
    #[Tool('private_tool', 'This should not be found')]
    private function privateMethod(): void
    {
    }
}

// Service with context injection support
class ContextAwareToolService
{
    private $currentContext = null;

    public function setCurrentContext($ctx): void
    {
        $this->currentContext = $ctx;
    }

    public function getCurrentContext()
    {
        return $this->currentContext;
    }

    #[Tool('context_tool', 'Tool that uses context')]
    public function contextTool(): array
    {
        return [
            'has_context' => $this->currentContext !== null,
            'channel' => $this->currentContext?->channel,
        ];
    }
}

// Service with various parameter types for coverage
class TypeTestService
{
    #[Tool('nullable_param', 'Tool with nullable param')]
    public function nullableParam(?string $value): array
    {
        return ['value' => $value];
    }

    #[Tool('float_param', 'Tool with float param')]
    public function floatParam(float $amount): array
    {
        return ['amount' => $amount];
    }

    #[Tool('bool_param', 'Tool with bool param')]
    public function boolParam(bool $flag): array
    {
        return ['flag' => $flag];
    }

    #[Tool('array_param', 'Tool with array param')]
    public function arrayParam(array $items): array
    {
        return ['items' => $items];
    }

    #[Tool('mixed_param', 'Tool with mixed/no type')]
    public function mixedParam($untypedParam): array
    {
        return ['untyped' => $untypedParam];
    }

    #[Tool('categorized_tool', 'Tool with category', category: 'testing')]
    public function categorizedTool(): array
    {
        return ['category' => 'testing'];
    }

    #[Tool('clamped_tool', 'Tool with clamps', clamps: ['value' => [0, 100]])]
    public function clampedTool(int $value): array
    {
        return ['value' => $value];
    }

    #[Tool('required_no_default', 'Tool with required param without default')]
    public function requiredNoDefault(string $required): array
    {
        return ['required' => $required];
    }
}

// Service covering the object-param gap fix (tool-runtime 0.6) — a PHP `array $param`
// declared with `#[Param(type: 'object', ...)]` must produce JSON-Schema `type: object` and
// accept an associative payload, instead of the inferred `type: array` (which requires a list
// and rejects `{"post_id": 1}` — see orch-f4-report.md finding #1).
class ObjectParamToolService
{
    #[Tool('update_post', 'Update fields on a post')]
    public function updatePost(
        int $post_id,
        #[Param(
            'Fields to update',
            type: 'object',
            properties: [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ]
        )]
        array $updates
    ): array {
        return ['post_id' => $post_id, 'updates' => $updates];
    }

    #[Tool('open_object_param', 'Tool with an object param and no declared shape')]
    public function openObjectParam(
        #[Param('Arbitrary payload', type: 'object')]
        array $payload
    ): array {
        return ['payload' => $payload];
    }
}

class ToolScannerTest extends TestCase
{
    private ToolRegistry $registry;
    private ToolScanner $scanner;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registry = new ToolRegistry($this->logger);
        $this->scanner = new ToolScanner($this->registry);
    }

    public function testScanFindsToolMethods(): void
    {
        $service = new TestToolService();
        $count = $this->scanner->scan($service);

        $this->assertEquals(4, $count); // list_items, create_item, delete_item, search_items
    }

    public function testScanRegistersToolsInRegistry(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $this->assertTrue($this->registry->has('list_items'));
        $this->assertTrue($this->registry->has('create_item'));
        $this->assertTrue($this->registry->has('delete_item'));
        $this->assertTrue($this->registry->has('search_items'));
    }

    public function testScanIgnoresNonToolMethods(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $this->assertFalse($this->registry->has('helperMethod'));
        $this->assertFalse($this->registry->has('privateMethod'));
        $this->assertFalse($this->registry->has('private_tool'));
    }

    public function testScannedToolIsCallable(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $result = $this->registry->call('list_items', ['page' => 2, 'limit' => 10]);

        $this->assertTrue($result->success);
        $this->assertEquals(['items' => [], 'page' => 2, 'limit' => 10], $result->data);
    }

    public function testScannedToolWithRequiredParams(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $result = $this->registry->call('create_item', [
            'name' => 'Test Item',
            'description' => 'A test description',
        ]);

        $this->assertTrue($result->success);
        $this->assertEquals('Test Item', $result->data['name']);
        $this->assertEquals('A test description', $result->data['description']);
    }

    public function testScannedToolValidatesRequiredParams(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        // Missing required 'description' parameter
        $result = $this->registry->call('create_item', ['name' => 'Test']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('description', $result->error);
    }

    public function testScannedToolPreservesScopes(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $definition = $this->registry->getDefinition('create_item');

        $this->assertEquals(['items:write'], $definition->scopes);
    }

    public function testSchemaGenerationForStringParam(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $searchTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'search_items'))[0];

        $this->assertEquals('string', $searchTool['inputSchema']['properties']['query']['type']);
    }

    public function testSchemaGenerationForIntParam(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $listTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'list_items'))[0];

        $this->assertEquals('integer', $listTool['inputSchema']['properties']['page']['type']);
        $this->assertEquals('integer', $listTool['inputSchema']['properties']['limit']['type']);
    }

    public function testSchemaIncludesDefaultValues(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $listTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'list_items'))[0];

        $this->assertEquals(1, $listTool['inputSchema']['properties']['page']['default']);
        $this->assertEquals(20, $listTool['inputSchema']['properties']['limit']['default']);
    }

    public function testSchemaIncludesEnumFromParamAttribute(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $searchTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'search_items'))[0];

        $this->assertEquals(['asc', 'desc'], $searchTool['inputSchema']['properties']['sort']['enum']);
    }

    public function testSchemaIncludesClampFromParamAttribute(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $searchTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'search_items'))[0];

        $this->assertEquals(1, $searchTool['inputSchema']['properties']['limit']['minimum']);
        $this->assertEquals(100, $searchTool['inputSchema']['properties']['limit']['maximum']);
    }

    public function testSchemaIncludesDescription(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $searchTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'search_items'))[0];

        $this->assertEquals('Search query', $searchTool['inputSchema']['properties']['query']['description']);
    }

    public function testContextInjection(): void
    {
        $service = new ContextAwareToolService();
        $this->scanner->scan($service);

        $ctx = new \Milpa\ToolRuntime\Contracts\ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: ['*']
        );

        $result = $this->registry->call('context_tool', [], $ctx);

        $this->assertTrue($result->success);
        $this->assertTrue($result->data['has_context']);
        $this->assertEquals('telegram', $result->data['channel']);
    }

    public function testScanEmptyServiceReturnsZero(): void
    {
        $emptyService = new class () {
            public function regularMethod(): void
            {
            }
        };

        $count = $this->scanner->scan($emptyService);

        $this->assertEquals(0, $count);
    }

    public function testScanMultipleServicesAccumulates(): void
    {
        $service1 = new TestToolService();
        $service2 = new ContextAwareToolService();

        $count1 = $this->scanner->scan($service1);
        $count2 = $this->scanner->scan($service2);

        $this->assertEquals(4, $count1);
        $this->assertEquals(1, $count2);
        $this->assertCount(5, $this->registry->getToolSummaries());
    }

    // ========== Additional Tests for Coverage ==========

    public function testNullableParamSchema(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $nullableTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'nullable_param'))[0];

        $this->assertEquals('string', $nullableTool['inputSchema']['properties']['value']['type']);
        $this->assertTrue($nullableTool['inputSchema']['properties']['value']['nullable']);
    }

    public function testFloatParamSchema(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $floatTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'float_param'))[0];

        $this->assertEquals('number', $floatTool['inputSchema']['properties']['amount']['type']);
    }

    public function testBoolParamSchema(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $boolTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'bool_param'))[0];

        $this->assertEquals('boolean', $boolTool['inputSchema']['properties']['flag']['type']);
    }

    public function testArrayParamSchema(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $arrayTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'array_param'))[0];

        $this->assertEquals('array', $arrayTool['inputSchema']['properties']['items']['type']);
    }

    public function testUntypedParamDefaultsToString(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $mixedTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'mixed_param'))[0];

        // Untyped params default to string
        $this->assertEquals('string', $mixedTool['inputSchema']['properties']['untypedParam']['type']);
    }

    public function testToolWithCategoryIsScanned(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        // Category is in Tool attribute but not stored in ToolDefinition
        // Test that the tool is still registered correctly
        $this->assertTrue($this->registry->has('categorized_tool'));
        $definition = $this->registry->getDefinition('categorized_tool');
        $this->assertNotNull($definition);
    }

    public function testToolWithClamps(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        $definition = $this->registry->getDefinition('clamped_tool');

        // Clamps are stored directly in ToolDefinition
        $this->assertNotEmpty($definition->clamps);
        $this->assertEquals(['value' => [0, 100]], $definition->clamps);
    }

    public function testRequiredParamWithoutDefault(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $requiredTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'required_no_default'))[0];

        // Should have 'required' in the schema
        $this->assertContains('required', $requiredTool['inputSchema']['required']);
    }

    public function testInvokeMethodWithMissingNonOptionalParam(): void
    {
        $service = new TypeTestService();
        $this->scanner->scan($service);

        // Call with empty args - should use null for missing non-optional param
        $result = $this->registry->call('required_no_default', []);

        // Should fail validation since required param is missing
        $this->assertFalse($result->success);
    }

    public function testInvokeMethodUsesDefaultValue(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        // Call without providing optional params - should use defaults
        $result = $this->registry->call('list_items', []);

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->data['page']);
        $this->assertEquals(20, $result->data['limit']);
    }

    public function testToolWithConfirmAttribute(): void
    {
        $service = new TestToolService();
        $this->scanner->scan($service);

        $definition = $this->registry->getDefinition('delete_item');

        $this->assertTrue($definition->requiresConfirmation);
    }

    public function testScanCountsAllToolMethods(): void
    {
        $service = new TypeTestService();
        $count = $this->scanner->scan($service);

        // TypeTestService has 8 tool methods
        $this->assertEquals(8, $count);
    }

    // ========== Object-param gap fix (tool-runtime 0.6) ==========

    public function testObjectParamGeneratesTypeObjectSchema(): void
    {
        $service = new ObjectParamToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $updateTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'update_post'))[0];

        $this->assertEquals('object', $updateTool['inputSchema']['properties']['updates']['type']);
        // Not the inferred 'array' the bare PHP `array $updates` type would otherwise produce.
        $this->assertNotEquals('array', $updateTool['inputSchema']['properties']['updates']['type']);
    }

    public function testObjectParamSchemaIncludesDeclaredProperties(): void
    {
        $service = new ObjectParamToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $updateTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'update_post'))[0];

        $this->assertEquals(
            ['title' => ['type' => 'string'], 'body' => ['type' => 'string']],
            $updateTool['inputSchema']['properties']['updates']['properties']
        );
    }

    public function testObjectParamAcceptsAssociativePayloadAndTheMethodReceivesItIntact(): void
    {
        // The exact repro from orch-f4-report.md finding #1: `{"post_id": 1}` decodes (upstream,
        // at the JSON transport boundary) to an associative PHP array, which previously failed
        // SchemaValidator's `array_is_list()` check because the inferred schema type was 'array'.
        $service = new ObjectParamToolService();
        $this->scanner->scan($service);

        $result = $this->registry->call('update_post', [
            'post_id' => 1,
            'updates' => ['title' => 'New title', 'body' => 'New body'],
        ]);

        $this->assertTrue($result->success, $result->error ?? '');
        $this->assertEquals(1, $result->data['post_id']);
        // The method receives a plain associative array — no hand json_decode() required.
        $this->assertSame(['title' => 'New title', 'body' => 'New body'], $result->data['updates']);
    }

    public function testObjectParamWithoutDeclaredPropertiesAcceptsArbitraryPayload(): void
    {
        $service = new ObjectParamToolService();
        $this->scanner->scan($service);

        $tools = $this->registry->getToolSummaries();
        $openTool = array_values(array_filter($tools, fn ($t) => $t['name'] === 'open_object_param'))[0];

        // No declared shape -> no `properties` key at all on the param schema (an open object;
        // never an empty array that would need json-safe stdClass normalization).
        $this->assertArrayNotHasKey('properties', $openTool['inputSchema']['properties']['payload']);

        $result = $this->registry->call('open_object_param', ['payload' => ['anything' => 'goes', 'nested' => ['x' => 1]]]);

        $this->assertTrue($result->success, $result->error ?? '');
        $this->assertSame(['anything' => 'goes', 'nested' => ['x' => 1]], $result->data['payload']);
    }

    public function testObjectParamAcceptsEmptyPayload(): void
    {
        $service = new ObjectParamToolService();
        $this->scanner->scan($service);

        // `{}` decodes to `[]` in PHP — must still validate as an object, not be rejected.
        $result = $this->registry->call('open_object_param', ['payload' => []]);

        $this->assertTrue($result->success, $result->error ?? '');
        $this->assertSame([], $result->data['payload']);
    }

    public function testObjectParamRejectsAScalarPayload(): void
    {
        $service = new ObjectParamToolService();
        $this->scanner->scan($service);

        $result = $this->registry->call('open_object_param', ['payload' => 'not-an-object']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('object', $result->error);
    }

    public function testListArrayParamStillRequiresAListNoRegression(): void
    {
        // Regression guard: a plain (non-overridden) `array $items` param must keep generating
        // `type: array` and keep requiring a JSON-Schema list — the object-param override is
        // strictly opt-in via `#[Param(type: 'object')]` and must not change default behavior.
        $service = new TypeTestService();
        $this->scanner->scan($service);

        $validList = $this->registry->call('array_param', ['items' => ['a', 'b', 'c']]);
        $this->assertTrue($validList->success, $validList->error ?? '');

        $invalidAssoc = $this->registry->call('array_param', ['items' => ['key' => 'value']]);
        $this->assertFalse($invalidAssoc->success);
        $this->assertStringContainsString('array', $invalidAssoc->error);
    }
}
