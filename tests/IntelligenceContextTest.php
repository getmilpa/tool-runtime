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
use Milpa\ToolRuntime\Contracts\IntelligenceContext;
use Milpa\ToolRuntime\ToolResult;

class IntelligenceContextTest extends TestCase
{
    public function testPreExecutionCreatesContextWithoutResult(): void
    {
        $context = IntelligenceContext::preExecution(
            toolName: 'create_note',
            args: ['nombre' => 'Test', 'app' => 'Generic']
        );

        $this->assertEquals('create_note', $context->getToolName());
        $this->assertEquals('Test', $context->getArg('nombre'));
        $this->assertFalse($context->hasResult());
        $this->assertNull($context->getResult());
    }

    public function testPostExecutionCreatesContextWithResult(): void
    {
        $result = ToolResult::success(['id' => 123, 'nombre' => 'Test']);

        $context = IntelligenceContext::postExecution(
            toolName: 'create_note',
            args: ['nombre' => 'Test'],
            result: $result,
            entityType: 'Note',
            entityId: 123
        );

        $this->assertTrue($context->hasResult());
        $this->assertTrue($context->isSuccessful());
        $this->assertEquals('Note', $context->getEntityType());
        $this->assertEquals(123, $context->getEntityId());
    }

    public function testWithResultAddsResultToContext(): void
    {
        $preContext = IntelligenceContext::preExecution('create_note', ['nombre' => 'Test']);
        $result = ToolResult::success(['id' => 456]);

        $postContext = $preContext->withResult($result);

        $this->assertFalse($preContext->hasResult()); // Original unchanged
        $this->assertTrue($postContext->hasResult());
        $this->assertSame($result, $postContext->getResult());
    }

    public function testWithEntityAddsEntityInfo(): void
    {
        $context = IntelligenceContext::preExecution('get_note', ['id' => 1]);
        $entity = new \stdClass();
        $entity->id = 1;

        $updated = $context->withEntity('Note', $entity, 1);

        $this->assertTrue($updated->hasEntity());
        $this->assertEquals('Note', $updated->getEntityType());
        $this->assertSame($entity, $updated->getEntity());
        $this->assertEquals(1, $updated->getEntityId());
    }

    public function testWithAppliedRulesAddsRules(): void
    {
        $context = IntelligenceContext::preExecution('create_note', []);

        $updated = $context->withAppliedRules([
            ['rule' => 'auto_nota', 'effect' => 'nota generated'],
        ]);

        $rules = $updated->getAppliedRules();
        $this->assertCount(1, $rules);
        $this->assertEquals('auto_nota', $rules[0]['rule']);
    }

    public function testWithExtraAddsExtraData(): void
    {
        $context = IntelligenceContext::preExecution('test', []);

        $updated = $context->withExtra(['custom_key' => 'custom_value']);

        $this->assertEquals('custom_value', $updated->getExtraValue('custom_key'));
        $this->assertNull($updated->getExtraValue('nonexistent'));
        $this->assertEquals('default', $updated->getExtraValue('nonexistent', 'default'));
    }

    public function testGetArgReturnsValueOrDefault(): void
    {
        $context = IntelligenceContext::preExecution('test', ['nombre' => 'Test']);

        $this->assertEquals('Test', $context->getArg('nombre'));
        $this->assertNull($context->getArg('missing'));
        $this->assertEquals('fallback', $context->getArg('missing', 'fallback'));
    }

    public function testIsCreateOperationDetectsCreateTools(): void
    {
        $createContext = IntelligenceContext::preExecution('create_note', []);
        $addContext = IntelligenceContext::preExecution('add_user', []);
        $getContext = IntelligenceContext::preExecution('get_note', []);

        $this->assertTrue($createContext->isCreateOperation());
        $this->assertTrue($addContext->isCreateOperation());
        $this->assertFalse($getContext->isCreateOperation());
    }

    public function testIsUpdateOperationDetectsUpdateTools(): void
    {
        $updateContext = IntelligenceContext::preExecution('update_note', []);
        $editContext = IntelligenceContext::preExecution('edit_user', []);
        $modifyContext = IntelligenceContext::preExecution('modify_settings', []);
        $getContext = IntelligenceContext::preExecution('get_note', []);

        $this->assertTrue($updateContext->isUpdateOperation());
        $this->assertTrue($editContext->isUpdateOperation());
        $this->assertTrue($modifyContext->isUpdateOperation());
        $this->assertFalse($getContext->isUpdateOperation());
    }

    public function testIsDeleteOperationDetectsDeleteTools(): void
    {
        $deleteContext = IntelligenceContext::preExecution('delete_note', []);
        $removeContext = IntelligenceContext::preExecution('remove_user', []);
        $getContext = IntelligenceContext::preExecution('get_note', []);

        $this->assertTrue($deleteContext->isDeleteOperation());
        $this->assertTrue($removeContext->isDeleteOperation());
        $this->assertFalse($getContext->isDeleteOperation());
    }

    public function testIsReadOperationDetectsReadTools(): void
    {
        $getContext = IntelligenceContext::preExecution('get_note', []);
        $listContext = IntelligenceContext::preExecution('list_notes', []);
        $findContext = IntelligenceContext::preExecution('find_available_notes', []);
        $searchContext = IntelligenceContext::preExecution('search_users', []);
        $createContext = IntelligenceContext::preExecution('create_note', []);

        $this->assertTrue($getContext->isReadOperation());
        $this->assertTrue($listContext->isReadOperation());
        $this->assertTrue($findContext->isReadOperation());
        $this->assertTrue($searchContext->isReadOperation());
        $this->assertFalse($createContext->isReadOperation());
    }

    public function testIsMutationDetectsCUD(): void
    {
        $createContext = IntelligenceContext::preExecution('create_note', []);
        $updateContext = IntelligenceContext::preExecution('update_note', []);
        $deleteContext = IntelligenceContext::preExecution('delete_note', []);
        $getContext = IntelligenceContext::preExecution('get_note', []);

        $this->assertTrue($createContext->isMutation());
        $this->assertTrue($updateContext->isMutation());
        $this->assertTrue($deleteContext->isMutation());
        $this->assertFalse($getContext->isMutation());
    }

    public function testGetOperationTypeReturnsCorrectType(): void
    {
        $this->assertEquals('create', IntelligenceContext::preExecution('create_note', [])->getOperationType());
        $this->assertEquals('update', IntelligenceContext::preExecution('update_user', [])->getOperationType());
        $this->assertEquals('delete', IntelligenceContext::preExecution('delete_item', [])->getOperationType());
        $this->assertEquals('read', IntelligenceContext::preExecution('get_note', [])->getOperationType());
        $this->assertEquals('unknown', IntelligenceContext::preExecution('custom_action', [])->getOperationType());
    }

    public function testInferEntityTypeFromToolExtractsType(): void
    {
        $cases = [
            'create_note' => 'Note',
            'update_user' => 'User',
            'delete_notebook' => 'Notebook',
            'get_brand' => 'Brand',
            'list_notes' => 'Note',
            'find_users' => 'User',
            'add_channel' => 'Channel',
            'remove_bot' => 'Bot',
            'edit_tipo' => 'Tipo',
            'search_items' => 'Item',
        ];

        foreach ($cases as $tool => $expectedType) {
            $context = IntelligenceContext::preExecution($tool, []);
            $this->assertEquals(
                $expectedType,
                $context->inferEntityTypeFromTool(),
                "Failed for tool: $tool"
            );
        }
    }

    public function testInferEntityTypeFromToolReturnsNullForUnknownPattern(): void
    {
        $context = IntelligenceContext::preExecution('custom_operation', []);

        $this->assertNull($context->inferEntityTypeFromTool());
    }

    public function testIsSuccessfulReturnsFalseWhenNoResult(): void
    {
        $context = IntelligenceContext::preExecution('test', []);

        $this->assertFalse($context->isSuccessful());
    }

    public function testIsSuccessfulReflectsResultStatus(): void
    {
        $successResult = ToolResult::success(['data' => 'value']);
        $errorResult = ToolResult::error('Something failed');

        $successContext = IntelligenceContext::preExecution('test', [])->withResult($successResult);
        $errorContext = IntelligenceContext::preExecution('test', [])->withResult($errorResult);

        $this->assertTrue($successContext->isSuccessful());
        $this->assertFalse($errorContext->isSuccessful());
    }

    public function testImmutabilityPreservesOriginal(): void
    {
        $original = IntelligenceContext::preExecution('test', ['key' => 'value']);
        $result = ToolResult::success(['id' => 1]);

        $withResult = $original->withResult($result);
        $withEntity = $original->withEntity('Test', null, 1);

        $this->assertFalse($original->hasResult());
        $this->assertNull($original->getEntityType());

        $this->assertTrue($withResult->hasResult());
        $this->assertEquals('Test', $withEntity->getEntityType());
    }
}
