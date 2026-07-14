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
use Milpa\ToolRuntime\Contracts\ToolIntelligence;

class ToolIntelligenceTest extends TestCase
{
    public function testEmptyCreatesEmptyInstance(): void
    {
        $intelligence = ToolIntelligence::empty();

        $this->assertEmpty($intelligence->suggestions);
        $this->assertEmpty($intelligence->warnings);
        $this->assertEmpty($intelligence->nextActions);
        $this->assertEmpty($intelligence->context);
        $this->assertEmpty($intelligence->businessRules);
        $this->assertTrue($intelligence->isEmpty());
    }

    public function testConstructorWithAllFields(): void
    {
        $intelligence = new ToolIntelligence(
            suggestions: ['Add bank field'],
            warnings: ['Missing account'],
            nextActions: [['tool' => 'get_note', 'params' => ['id' => 1], 'label' => 'View']],
            context: ['completeness' => 0.8],
            businessRules: ['applied' => ['auto_nota']]
        );

        $this->assertCount(1, $intelligence->suggestions);
        $this->assertCount(1, $intelligence->warnings);
        $this->assertCount(1, $intelligence->nextActions);
        $this->assertEquals(0.8, $intelligence->context['completeness']);
        $this->assertFalse($intelligence->isEmpty());
    }

    public function testFromArrayCreatesInstance(): void
    {
        $data = [
            'suggestions' => ['Suggestion 1', 'Suggestion 2'],
            'warnings' => ['Warning 1'],
            'next_actions' => [
                ['tool' => 'update_note', 'params' => [], 'label' => 'Update'],
            ],
            'context' => ['entity_type' => 'Note', 'completeness' => 0.6],
            'business_rules' => ['tips' => ['Tip 1']],
        ];

        $intelligence = ToolIntelligence::fromArray($data);

        $this->assertCount(2, $intelligence->suggestions);
        $this->assertEquals('Warning 1', $intelligence->warnings[0]);
        $this->assertEquals('update_note', $intelligence->nextActions[0]['tool']);
        $this->assertEquals('Note', $intelligence->context['entity_type']);
    }

    public function testFromArrayHandlesAlternativeKeys(): void
    {
        $data = [
            'nextActions' => [['tool' => 'test', 'label' => 'Test']],
            'businessRules' => ['applied' => []],
        ];

        $intelligence = ToolIntelligence::fromArray($data);

        $this->assertCount(1, $intelligence->nextActions);
        $this->assertArrayHasKey('applied', $intelligence->businessRules);
    }

    public function testWithSuggestionsAddsSuggestions(): void
    {
        $intelligence = ToolIntelligence::empty()
            ->withSuggestions(['First suggestion']);

        $this->assertCount(1, $intelligence->suggestions);
        $this->assertEquals('First suggestion', $intelligence->suggestions[0]);
    }

    public function testWithSuggestionsMergesWithExisting(): void
    {
        $intelligence = new ToolIntelligence(suggestions: ['First']);
        $updated = $intelligence->withSuggestions(['Second']);

        $this->assertCount(2, $updated->suggestions);
        $this->assertEquals('First', $updated->suggestions[0]);
        $this->assertEquals('Second', $updated->suggestions[1]);
    }

    public function testWithWarningsAddsWarnings(): void
    {
        $intelligence = ToolIntelligence::empty()
            ->withWarnings(['Warning 1', 'Warning 2']);

        $this->assertCount(2, $intelligence->warnings);
        $this->assertTrue($intelligence->hasWarnings());
    }

    public function testWithNextActionsAddsActions(): void
    {
        $intelligence = ToolIntelligence::empty()
            ->withNextActions([
                ['tool' => 'get_note', 'params' => ['id' => 123], 'label' => 'View note'],
            ]);

        $this->assertCount(1, $intelligence->nextActions);
        $this->assertEquals('get_note', $intelligence->nextActions[0]['tool']);
        $this->assertEquals(123, $intelligence->nextActions[0]['params']['id']);
    }

    public function testWithContextSetsContext(): void
    {
        $intelligence = ToolIntelligence::empty()
            ->withContext(['entity_type' => 'Note', 'completeness' => 0.75]);

        $this->assertEquals('Note', $intelligence->context['entity_type']);
        $this->assertEquals(0.75, $intelligence->getCompleteness());
    }

    public function testWithBusinessRulesSetsRules(): void
    {
        $intelligence = ToolIntelligence::empty()
            ->withBusinessRules([
                'applied' => [['rule' => 'auto_nota', 'effect' => 'nota generated']],
                'would_block' => [],
                'tips' => ['Tip 1'],
            ]);

        $this->assertCount(1, $intelligence->businessRules['applied']);
        $this->assertFalse($intelligence->hasBlockingRules());
    }

    public function testHasBlockingRulesReturnsTrueWhenPresent(): void
    {
        $intelligence = new ToolIntelligence(
            businessRules: [
                'would_block' => [
                    ['rule' => 'max_users', 'if' => 'user_count >= 3'],
                ],
            ]
        );

        $this->assertTrue($intelligence->hasBlockingRules());
    }

    public function testMergeCombinesTwoIntelligences(): void
    {
        $first = new ToolIntelligence(
            suggestions: ['First suggestion'],
            warnings: ['First warning'],
            context: ['completeness' => 0.5]
        );

        $second = new ToolIntelligence(
            suggestions: ['Second suggestion'],
            nextActions: [['tool' => 'test', 'label' => 'Test']],
            context: ['entity_id' => 123]
        );

        $merged = $first->merge($second);

        $this->assertCount(2, $merged->suggestions);
        $this->assertCount(1, $merged->warnings);
        $this->assertCount(1, $merged->nextActions);
        $this->assertEquals(0.5, $merged->context['completeness']);
        $this->assertEquals(123, $merged->context['entity_id']);
    }

    public function testGetCompletenessReturnsDefaultWhenMissing(): void
    {
        $intelligence = ToolIntelligence::empty();

        $this->assertEquals(1.0, $intelligence->getCompleteness());
    }

    public function testGetMissingRecommendedReturnsEmptyWhenMissing(): void
    {
        $intelligence = ToolIntelligence::empty();

        $this->assertEmpty($intelligence->getMissingRecommended());
    }

    public function testGetMissingRecommendedReturnsFields(): void
    {
        $intelligence = new ToolIntelligence(
            context: ['missing_recommended' => ['bank', 'account']]
        );

        $missing = $intelligence->getMissingRecommended();

        $this->assertCount(2, $missing);
        $this->assertContains('bank', $missing);
        $this->assertContains('account', $missing);
    }

    public function testToArrayOnlyIncludesNonEmptySections(): void
    {
        $intelligence = new ToolIntelligence(
            suggestions: ['A suggestion'],
            warnings: [], // Empty, should be excluded
            nextActions: [],
            context: ['completeness' => 0.8],
            businessRules: []
        );

        $array = $intelligence->toArray();

        $this->assertArrayHasKey('suggestions', $array);
        $this->assertArrayNotHasKey('warnings', $array);
        $this->assertArrayNotHasKey('next_actions', $array);
        $this->assertArrayHasKey('context', $array);
        $this->assertArrayNotHasKey('business_rules', $array);
    }

    public function testJsonSerializeReturnsArray(): void
    {
        $intelligence = new ToolIntelligence(
            suggestions: ['Test'],
            context: ['completeness' => 1.0]
        );

        $json = json_encode($intelligence);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertEquals(['Test'], $decoded['suggestions']);
        $this->assertEquals(1.0, $decoded['context']['completeness']);
    }

    public function testImmutabilityPreservesOriginal(): void
    {
        $original = ToolIntelligence::empty();
        $modified = $original->withSuggestions(['New suggestion']);

        $this->assertEmpty($original->suggestions);
        $this->assertCount(1, $modified->suggestions);
    }
}
