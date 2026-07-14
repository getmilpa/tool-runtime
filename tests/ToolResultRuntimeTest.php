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
use Milpa\ToolRuntime\ToolResult;

/**
 * Tests for the Core ToolResult class (different from app/Contracts/ToolResult)
 */
class ToolResultRuntimeTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = ToolResult::success(['id' => 1], 'Created successfully');

        $this->assertTrue($result->success);
        $this->assertEquals(['id' => 1], $result->data);
        $this->assertEquals('Created successfully', $result->message);
        $this->assertNull($result->error);
    }

    public function testSuccessWithMeta(): void
    {
        $result = ToolResult::success(['data' => 'test'], null, ['custom' => 'meta']);

        $this->assertEquals(['custom' => 'meta'], $result->meta);
    }

    public function testErrorFactory(): void
    {
        $result = ToolResult::error('Something went wrong', ['partial' => 'data']);

        $this->assertFalse($result->success);
        $this->assertEquals('Something went wrong', $result->error);
        $this->assertEquals(['partial' => 'data'], $result->data);
        $this->assertEquals('error', $result->meta['type']);
    }

    public function testPaginatedFactory(): void
    {
        $items = [['id' => 1], ['id' => 2], ['id' => 3]];
        $result = ToolResult::paginated($items, 2, 50, 10, 'Page 2 of 5');

        $this->assertTrue($result->success);
        $this->assertEquals($items, $result->data);
        $this->assertEquals('Page 2 of 5', $result->message);
        $this->assertEquals('list', $result->meta['type']);

        $pagination = $result->getPagination();
        $this->assertEquals(2, $pagination['page']);
        $this->assertEquals(5, $pagination['total_pages']);
        $this->assertEquals(50, $pagination['total_items']);
        $this->assertEquals(10, $pagination['limit']);
        $this->assertTrue($pagination['has_prev']);
        $this->assertTrue($pagination['has_next']);
    }

    public function testPaginatedFirstPage(): void
    {
        $result = ToolResult::paginated([], 1, 30, 10);

        $pagination = $result->getPagination();
        $this->assertFalse($pagination['has_prev']);
        $this->assertTrue($pagination['has_next']);
    }

    public function testPaginatedLastPage(): void
    {
        $result = ToolResult::paginated([], 3, 30, 10);

        $pagination = $result->getPagination();
        $this->assertTrue($pagination['has_prev']);
        $this->assertFalse($pagination['has_next']);
    }

    public function testPaginatedSinglePage(): void
    {
        $result = ToolResult::paginated([], 1, 5, 10);

        $pagination = $result->getPagination();
        $this->assertFalse($pagination['has_prev']);
        $this->assertFalse($pagination['has_next']);
        $this->assertEquals(1, $pagination['total_pages']);
    }

    public function testDetailFactory(): void
    {
        $item = ['id' => 1, 'name' => 'Test Item', 'price' => 100];
        $actions = ['edit', 'delete'];
        $result = ToolResult::detail($item, 'Product', 'Product details', $actions);

        $this->assertTrue($result->success);
        $this->assertEquals($item, $result->data);
        $this->assertEquals('Product details', $result->message);
        $this->assertEquals('detail', $result->meta['type']);
        $this->assertEquals('Product', $result->meta['entity']);
        $this->assertEquals($actions, $result->getActions());
    }

    public function testConfirmationFactory(): void
    {
        $details = ['name' => 'Important Item', 'status' => 'active'];
        $result = ToolResult::confirmation(
            'Are you sure you want to delete this?',
            $details,
            'delete',
            'Item',
            123
        );

        $this->assertTrue($result->success);
        $this->assertEquals($details, $result->data);
        $this->assertEquals('Are you sure you want to delete this?', $result->message);
        $this->assertTrue($result->requiresConfirmation());
        $this->assertEquals('confirmation', $result->getType());
        $this->assertEquals('delete', $result->meta['action']);
        $this->assertEquals('Item', $result->meta['target']);
        $this->assertEquals(123, $result->meta['target_id']);
    }

    public function testBlockedFactory(): void
    {
        $result = ToolResult::blocked('Item is in use', 'Try again later');

        $this->assertFalse($result->success);
        $this->assertEquals('Item is in use', $result->error);
        $this->assertTrue($result->isBlocked());
        $this->assertEquals('blocked', $result->getType());
        $this->assertEquals('Try again later', $result->meta['suggestion']);
    }

    public function testBlockedWithoutSuggestion(): void
    {
        $result = ToolResult::blocked('Cannot proceed');

        $this->assertTrue($result->isBlocked());
        $this->assertNull($result->meta['suggestion']);
    }

    public function testRequiresConfirmationFalseByDefault(): void
    {
        $result = ToolResult::success(['data']);

        $this->assertFalse($result->requiresConfirmation());
    }

    public function testIsBlockedFalseByDefault(): void
    {
        $result = ToolResult::success(['data']);

        $this->assertFalse($result->isBlocked());
    }

    public function testGetTypeDefault(): void
    {
        $result = ToolResult::success(['data']);

        $this->assertEquals('generic', $result->getType());
    }

    public function testGetPaginationNull(): void
    {
        $result = ToolResult::success(['data']);

        $this->assertNull($result->getPagination());
    }

    public function testGetActionsEmpty(): void
    {
        $result = ToolResult::success(['data']);

        $this->assertEquals([], $result->getActions());
    }

    public function testJsonSerialize(): void
    {
        $result = ToolResult::success(['id' => 1], 'Done', ['custom' => true]);

        $serialized = $result->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertTrue($serialized['success']);
        $this->assertEquals(['id' => 1], $serialized['data']);
        $this->assertEquals('Done', $serialized['message']);
        $this->assertNull($serialized['error']);
        $this->assertEquals(['custom' => true], $serialized['meta']);
    }

    public function testToJson(): void
    {
        $result = ToolResult::success(['key' => 'value']);

        $json = $result->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertTrue($decoded['success']);
    }

    public function testToArray(): void
    {
        $result = ToolResult::error('Error message');

        $array = $result->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('Error message', $array['error']);
    }

    public function testJsonSerializableInterface(): void
    {
        $result = ToolResult::success(['test']);

        $json = json_encode($result);

        $this->assertJson($json);
    }

    public function testPaginatedWithExtraMeta(): void
    {
        $result = ToolResult::paginated([], 1, 10, 10, null, ['filter' => 'active']);

        $this->assertEquals('active', $result->meta['filter']);
        $this->assertArrayHasKey('pagination', $result->meta);
    }

    public function testConstructorDirectly(): void
    {
        $result = new ToolResult(
            success: true,
            data: ['direct' => 'construction'],
            message: 'Direct message',
            error: null,
            meta: ['type' => 'custom']
        );

        $this->assertTrue($result->success);
        $this->assertEquals(['direct' => 'construction'], $result->data);
        $this->assertEquals('custom', $result->getType());
    }
}
