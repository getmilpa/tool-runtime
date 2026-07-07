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
use Milpa\ToolRuntime\Contracts\ToolIntelligence;
use Milpa\ToolRuntime\IntelligentToolResult;
use Milpa\ToolRuntime\ToolResult;

class IntelligentToolResultTest extends TestCase
{
    private function createIntelligence(): ToolIntelligence
    {
        return new ToolIntelligence(
            suggestions: ['Consider adding bank field'],
            warnings: ['Missing account'],
            nextActions: [
                ['tool' => 'get_note', 'params' => ['id' => 123], 'label' => 'View note'],
            ],
            context: [
                'entity_type' => 'Note',
                'completeness' => 0.6,
            ],
            businessRules: [
                'applied' => [['rule' => 'auto_nota', 'effect' => 'nota generated']],
            ]
        );
    }

    public function testFromToolResultCreatesIntelligentResult(): void
    {
        $originalResult = ToolResult::success(['id' => 123, 'nombre' => 'Test']);
        $intelligence = $this->createIntelligence();

        $intelligentResult = IntelligentToolResult::fromToolResult(
            $originalResult,
            $intelligence,
            'create_note',
            "Crear Note 'Test'"
        );

        $this->assertTrue($intelligentResult->success);
        $this->assertEquals(['id' => 123, 'nombre' => 'Test'], $intelligentResult->data);
        $this->assertEquals('create_note', $intelligentResult->getAction());
        $this->assertEquals("Crear Note 'Test'", $intelligentResult->getAttempt());
        $this->assertTrue($intelligentResult->hasIntelligence());
    }

    public function testSuccessWithIntelligenceCreatesSuccessResult(): void
    {
        $intelligence = $this->createIntelligence();

        $result = IntelligentToolResult::successWithIntelligence(
            data: ['id' => 456],
            intelligence: $intelligence,
            action: 'update_note',
            attempt: 'Actualizar Note #456',
            message: 'Note updated successfully'
        );

        $this->assertTrue($result->success);
        $this->assertEquals('Note updated successfully', $result->message);
        $this->assertNull($result->error);
    }

    public function testErrorWithIntelligenceCreatesErrorResult(): void
    {
        $intelligence = new ToolIntelligence(
            suggestions: ['Use find_available_notes to find notes with space'],
            nextActions: [
                ['tool' => 'find_available_notes', 'params' => [], 'label' => 'Find available notes'],
            ]
        );

        $result = IntelligentToolResult::errorWithIntelligence(
            error: 'Note has reached maximum capacity',
            intelligence: $intelligence,
            action: 'assign_user_to_note',
            attempt: 'Asignar usuario a la note #123'
        );

        $this->assertFalse($result->success);
        $this->assertEquals('Note has reached maximum capacity', $result->error);
        $this->assertTrue($result->hasIntelligence());
        $this->assertContains('Use find_available_notes to find notes with space', $result->getSuggestions());
    }

    public function testBlockedWithIntelligenceCreatesBlockedResult(): void
    {
        $intelligence = new ToolIntelligence(
            suggestions: ['Increase max_users or find another note'],
            businessRules: [
                'would_block' => [['rule' => 'max_users', 'reason' => 'Capacity reached']],
            ]
        );

        $result = IntelligentToolResult::blockedWithIntelligence(
            reason: 'Operation blocked by max_users rule',
            intelligence: $intelligence,
            action: 'add_user',
            attempt: 'Agregar usuario a note'
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->isBlocked());
    }

    public function testGetSuggestionsReturnsSuggestions(): void
    {
        $intelligence = new ToolIntelligence(suggestions: ['Suggestion 1', 'Suggestion 2']);
        $result = IntelligentToolResult::successWithIntelligence(
            ['data' => 'value'],
            $intelligence,
            'test',
            'Test'
        );

        $suggestions = $result->getSuggestions();

        $this->assertCount(2, $suggestions);
        $this->assertEquals('Suggestion 1', $suggestions[0]);
    }

    public function testGetWarningsReturnsWarnings(): void
    {
        $intelligence = new ToolIntelligence(warnings: ['Warning 1']);
        $result = IntelligentToolResult::successWithIntelligence(
            null,
            $intelligence,
            'test',
            'Test'
        );

        $warnings = $result->getWarnings();

        $this->assertCount(1, $warnings);
        $this->assertEquals('Warning 1', $warnings[0]);
    }

    public function testGetNextActionsReturnsActions(): void
    {
        $intelligence = new ToolIntelligence(
            nextActions: [
                ['tool' => 'get_note', 'params' => ['id' => 1], 'label' => 'View'],
                ['tool' => 'list_notes', 'params' => [], 'label' => 'List'],
            ]
        );
        $result = IntelligentToolResult::successWithIntelligence(
            null,
            $intelligence,
            'create_note',
            'Create note'
        );

        $actions = $result->getNextActions();

        $this->assertCount(2, $actions);
        $this->assertEquals('get_note', $actions[0]['tool']);
    }

    public function testWithIntelligenceAddsIntelligence(): void
    {
        $originalResult = new IntelligentToolResult(
            success: true,
            data: ['id' => 1],
            action: 'test',
            attempt: 'Test'
        );

        $intelligence = $this->createIntelligence();
        $withIntelligence = $originalResult->withIntelligence($intelligence);

        $this->assertFalse($originalResult->hasIntelligence());
        $this->assertTrue($withIntelligence->hasIntelligence());
    }

    public function testMergeIntelligenceCombinesIntelligence(): void
    {
        $firstIntelligence = new ToolIntelligence(suggestions: ['First']);
        $result = IntelligentToolResult::successWithIntelligence(
            ['id' => 1],
            $firstIntelligence,
            'test',
            'Test'
        );

        $secondIntelligence = new ToolIntelligence(suggestions: ['Second']);
        $merged = $result->mergeIntelligence($secondIntelligence);

        $suggestions = $merged->getSuggestions();
        $this->assertCount(2, $suggestions);
        $this->assertEquals('First', $suggestions[0]);
        $this->assertEquals('Second', $suggestions[1]);
    }

    public function testJsonSerializeReturnsItpFormat(): void
    {
        $intelligence = $this->createIntelligence();
        $result = IntelligentToolResult::successWithIntelligence(
            data: ['id' => 123, 'nombre' => 'Test'],
            intelligence: $intelligence,
            action: 'create_note',
            attempt: "Crear Note 'Test'",
            meta: ['took_ms' => 45]
        );

        $serialized = $result->jsonSerialize();

        $this->assertEquals('create_note', $serialized['action']);
        $this->assertEquals("Crear Note 'Test'", $serialized['attempt']);
        $this->assertEquals(['id' => 123, 'nombre' => 'Test'], $serialized['response']);
        $this->assertTrue($serialized['success']);
        $this->assertNull($serialized['reason']);
        $this->assertArrayHasKey('intelligence', $serialized);
        $this->assertArrayHasKey('suggestions', $serialized['intelligence']);
        $this->assertArrayHasKey('meta', $serialized);
    }

    public function testJsonSerializeExcludesEmptyIntelligence(): void
    {
        $result = new IntelligentToolResult(
            success: true,
            data: ['id' => 1],
            action: 'test',
            attempt: 'Test'
        );

        $serialized = $result->jsonSerialize();

        $this->assertArrayNotHasKey('intelligence', $serialized);
    }

    public function testToStandardFormatReturnsToolResultFormat(): void
    {
        $intelligence = $this->createIntelligence();
        $result = IntelligentToolResult::successWithIntelligence(
            data: ['id' => 1],
            intelligence: $intelligence,
            action: 'test',
            attempt: 'Test',
            message: 'Success'
        );

        $standard = $result->toStandardFormat();

        $this->assertArrayHasKey('success', $standard);
        $this->assertArrayHasKey('data', $standard);
        $this->assertArrayHasKey('message', $standard);
        $this->assertArrayHasKey('error', $standard);
        $this->assertArrayHasKey('meta', $standard);
        $this->assertArrayNotHasKey('intelligence', $standard);
        $this->assertArrayNotHasKey('action', $standard);
    }

    public function testToCompactFormatReturnsMinimalResponse(): void
    {
        $intelligence = new ToolIntelligence(
            suggestions: ['Sug 1', 'Sug 2', 'Sug 3', 'Sug 4', 'Sug 5'],
            nextActions: [
                ['tool' => 'a', 'label' => 'A'],
                ['tool' => 'b', 'label' => 'B'],
                ['tool' => 'c', 'label' => 'C'],
                ['tool' => 'd', 'label' => 'D'],
            ]
        );
        $result = IntelligentToolResult::successWithIntelligence(
            data: ['id' => 1],
            intelligence: $intelligence,
            action: 'test',
            attempt: 'Test'
        );

        $compact = $result->toCompactFormat();

        $this->assertArrayHasKey('success', $compact);
        $this->assertArrayHasKey('data', $compact);
        $this->assertArrayNotHasKey('error', $compact); // No error, so excluded
        $this->assertCount(3, $compact['suggestions']); // Limited to 3
        $this->assertCount(3, $compact['next_actions']); // Limited to 3
    }

    public function testToCompactFormatIncludesErrorWhenPresent(): void
    {
        $intelligence = ToolIntelligence::empty();
        $result = IntelligentToolResult::errorWithIntelligence(
            error: 'Something failed',
            intelligence: $intelligence,
            action: 'test',
            attempt: 'Test'
        );

        $compact = $result->toCompactFormat();

        $this->assertFalse($compact['success']);
        $this->assertArrayHasKey('error', $compact);
        $this->assertEquals('Something failed', $compact['error']);
    }

    public function testGettersReturnEmptyArraysWhenNoIntelligence(): void
    {
        $result = new IntelligentToolResult(
            success: true,
            data: ['id' => 1],
            action: 'test',
            attempt: 'Test'
        );

        $this->assertEmpty($result->getSuggestions());
        $this->assertEmpty($result->getWarnings());
        $this->assertEmpty($result->getNextActions());
    }

    public function testHasIntelligenceReturnsFalseForEmptyIntelligence(): void
    {
        $emptyIntelligence = ToolIntelligence::empty();
        $result = IntelligentToolResult::successWithIntelligence(
            ['data' => 'value'],
            $emptyIntelligence,
            'test',
            'Test'
        );

        $this->assertFalse($result->hasIntelligence());
    }

    public function testInheritanceFromToolResult(): void
    {
        $result = IntelligentToolResult::successWithIntelligence(
            data: ['items' => [1, 2, 3]],
            intelligence: ToolIntelligence::empty(),
            action: 'list_items',
            attempt: 'List items'
        );

        // Should inherit ToolResult methods
        $this->assertTrue($result->success);
        $this->assertEquals(['items' => [1, 2, 3]], $result->data);
        $this->assertFalse($result->requiresConfirmation());
        $this->assertFalse($result->isBlocked());
    }

    public function testJsonEncodingProducesValidJson(): void
    {
        $intelligence = $this->createIntelligence();
        $result = IntelligentToolResult::successWithIntelligence(
            data: ['id' => 1, 'nombre' => 'Test with "quotes" and unicodé'],
            intelligence: $intelligence,
            action: 'create_note',
            attempt: 'Create note'
        );

        $json = json_encode($result);

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('create_note', $decoded['action']);
        $this->assertStringContainsString('quotes', $decoded['response']['nombre']);
    }
}
