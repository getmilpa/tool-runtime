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
use Milpa\ToolRuntime\Contracts\ToolIntelligence;
use Milpa\ToolRuntime\IntelligenceAggregator;
use Milpa\ToolRuntime\Contracts\IntelligenceProviderInterface;
use Milpa\ToolRuntime\IntelligentToolResult;
use Milpa\ToolRuntime\ToolResult;

class IntelligenceAggregatorTest extends TestCase
{
    private function createMockProvider(
        string $name,
        int $priority,
        string $phase,
        bool $supports,
        ?ToolIntelligence $intelligence = null
    ): IntelligenceProviderInterface {
        $provider = $this->createMock(IntelligenceProviderInterface::class);

        $provider->method('getName')->willReturn($name);
        $provider->method('getPriority')->willReturn($priority);
        $provider->method('getPhase')->willReturn($phase);
        $provider->method('supports')->willReturn($supports);
        $provider->method('provide')->willReturn($intelligence ?? ToolIntelligence::empty());

        return $provider;
    }

    public function testRegisterProviderAddsProvider(): void
    {
        $aggregator = new IntelligenceAggregator();
        $provider = $this->createMockProvider('test_provider', 50, 'post', true);

        $aggregator->registerProvider($provider);

        $this->assertTrue($aggregator->hasProvider('test_provider'));
        $this->assertEquals(1, $aggregator->getProviderCount());
    }

    public function testRegisterProvidersAddsMultipleProviders(): void
    {
        $aggregator = new IntelligenceAggregator();
        $providers = [
            $this->createMockProvider('provider_a', 50, 'post', true),
            $this->createMockProvider('provider_b', 100, 'pre', true),
        ];

        $aggregator->registerProviders($providers);

        $this->assertEquals(2, $aggregator->getProviderCount());
        $this->assertContains('provider_a', $aggregator->getProviderNames());
        $this->assertContains('provider_b', $aggregator->getProviderNames());
    }

    public function testRemoveProviderRemovesProvider(): void
    {
        $aggregator = new IntelligenceAggregator();
        $aggregator->registerProvider($this->createMockProvider('to_remove', 50, 'post', true));

        $this->assertTrue($aggregator->hasProvider('to_remove'));

        $result = $aggregator->removeProvider('to_remove');

        $this->assertTrue($result);
        $this->assertFalse($aggregator->hasProvider('to_remove'));
    }

    public function testRemoveProviderReturnsFalseForNonexistent(): void
    {
        $aggregator = new IntelligenceAggregator();

        $result = $aggregator->removeProvider('nonexistent');

        $this->assertFalse($result);
    }

    public function testClearProvidersRemovesAll(): void
    {
        $aggregator = new IntelligenceAggregator();
        $aggregator->registerProviders([
            $this->createMockProvider('a', 50, 'post', true),
            $this->createMockProvider('b', 50, 'post', true),
        ]);

        $aggregator->clearProviders();

        $this->assertEquals(0, $aggregator->getProviderCount());
    }

    public function testGatherPreOnlyRunsPrePhaseProviders(): void
    {
        $aggregator = new IntelligenceAggregator();

        $preProvider = $this->createMockProvider(
            'pre_provider',
            100,
            'pre',
            true,
            new ToolIntelligence(warnings: ['Pre warning'])
        );
        $postProvider = $this->createMockProvider(
            'post_provider',
            100,
            'post',
            true,
            new ToolIntelligence(suggestions: ['Post suggestion'])
        );

        $aggregator->registerProviders([$preProvider, $postProvider]);

        $context = IntelligenceContext::preExecution('create_note', []);
        $intelligence = $aggregator->gatherPre($context);

        $this->assertCount(1, $intelligence->warnings);
        $this->assertEquals('Pre warning', $intelligence->warnings[0]);
        $this->assertEmpty($intelligence->suggestions);
    }

    public function testGatherPostOnlyRunsPostPhaseProviders(): void
    {
        $aggregator = new IntelligenceAggregator();

        $preProvider = $this->createMockProvider(
            'pre_provider',
            100,
            'pre',
            true,
            new ToolIntelligence(warnings: ['Pre warning'])
        );
        $postProvider = $this->createMockProvider(
            'post_provider',
            100,
            'post',
            true,
            new ToolIntelligence(suggestions: ['Post suggestion'])
        );

        $aggregator->registerProviders([$preProvider, $postProvider]);

        $result = ToolResult::success(['id' => 1]);
        $context = IntelligenceContext::preExecution('create_note', [])->withResult($result);
        $intelligence = $aggregator->gatherPost($context);

        $this->assertCount(1, $intelligence->suggestions);
        $this->assertEquals('Post suggestion', $intelligence->suggestions[0]);
        $this->assertEmpty($intelligence->warnings);
    }

    public function testGatherRunsBothPhaseProviders(): void
    {
        $aggregator = new IntelligenceAggregator();

        $bothProvider = $this->createMockProvider(
            'both_provider',
            100,
            'both',
            true,
            new ToolIntelligence(suggestions: ['Both phase suggestion'])
        );

        $aggregator->registerProvider($bothProvider);

        $context = IntelligenceContext::preExecution('test', []);
        $preIntelligence = $aggregator->gatherPre($context);
        $postIntelligence = $aggregator->gatherPost($context);

        $this->assertCount(1, $preIntelligence->suggestions);
        $this->assertCount(1, $postIntelligence->suggestions);
    }

    public function testGatherSkipsProvidersWithoutSupport(): void
    {
        $aggregator = new IntelligenceAggregator();

        $supportedProvider = $this->createMockProvider(
            'supported',
            100,
            'post',
            true,
            new ToolIntelligence(suggestions: ['Supported'])
        );
        $unsupportedProvider = $this->createMockProvider(
            'unsupported',
            100,
            'post',
            false,
            new ToolIntelligence(suggestions: ['Should not appear'])
        );

        $aggregator->registerProviders([$supportedProvider, $unsupportedProvider]);

        $result = ToolResult::success([]);
        $context = IntelligenceContext::preExecution('test', [])->withResult($result);
        $intelligence = $aggregator->gatherPost($context);

        $this->assertCount(1, $intelligence->suggestions);
        $this->assertEquals('Supported', $intelligence->suggestions[0]);
    }

    public function testGatherMergesMultipleProviders(): void
    {
        $aggregator = new IntelligenceAggregator();

        $provider1 = $this->createMockProvider(
            'provider1',
            100,
            'post',
            true,
            new ToolIntelligence(
                suggestions: ['Suggestion 1'],
                warnings: ['Warning 1']
            )
        );
        $provider2 = $this->createMockProvider(
            'provider2',
            50,
            'post',
            true,
            new ToolIntelligence(
                suggestions: ['Suggestion 2'],
                nextActions: [['tool' => 'test', 'label' => 'Test']]
            )
        );

        $aggregator->registerProviders([$provider1, $provider2]);

        $result = ToolResult::success([]);
        $context = IntelligenceContext::preExecution('test', [])->withResult($result);
        $intelligence = $aggregator->gatherPost($context);

        $this->assertCount(2, $intelligence->suggestions);
        $this->assertCount(1, $intelligence->warnings);
        $this->assertCount(1, $intelligence->nextActions);
    }

    public function testGatherSortsProvidersByPriority(): void
    {
        $aggregator = new IntelligenceAggregator();

        // Register in reverse priority order
        $lowPriority = $this->createMockProvider(
            'low',
            10,
            'post',
            true,
            new ToolIntelligence(suggestions: ['Low priority'])
        );
        $highPriority = $this->createMockProvider(
            'high',
            100,
            'post',
            true,
            new ToolIntelligence(suggestions: ['High priority'])
        );
        $medPriority = $this->createMockProvider(
            'med',
            50,
            'post',
            true,
            new ToolIntelligence(suggestions: ['Med priority'])
        );

        $aggregator->registerProviders([$lowPriority, $highPriority, $medPriority]);

        $result = ToolResult::success([]);
        $context = IntelligenceContext::preExecution('test', [])->withResult($result);
        $intelligence = $aggregator->gatherPost($context);

        // High priority runs first, so its suggestion comes first
        $this->assertEquals('High priority', $intelligence->suggestions[0]);
        $this->assertEquals('Med priority', $intelligence->suggestions[1]);
        $this->assertEquals('Low priority', $intelligence->suggestions[2]);
    }

    public function testGatherAllMergesPreAndPost(): void
    {
        $aggregator = new IntelligenceAggregator();

        $preProvider = $this->createMockProvider(
            'pre',
            100,
            'pre',
            true,
            new ToolIntelligence(warnings: ['Pre warning'])
        );
        $postProvider = $this->createMockProvider(
            'post',
            100,
            'post',
            true,
            new ToolIntelligence(suggestions: ['Post suggestion'])
        );

        $aggregator->registerProviders([$preProvider, $postProvider]);

        $preContext = IntelligenceContext::preExecution('create_note', []);
        $result = ToolResult::success(['id' => 1]);
        $postContext = $preContext->withResult($result);

        $intelligence = $aggregator->gatherAll($preContext, $postContext);

        $this->assertCount(1, $intelligence->warnings);
        $this->assertCount(1, $intelligence->suggestions);
    }

    public function testGatherHandlesProviderExceptions(): void
    {
        $aggregator = new IntelligenceAggregator();

        // Create a provider that throws
        $failingProvider = $this->createMock(IntelligenceProviderInterface::class);
        $failingProvider->method('getName')->willReturn('failing');
        $failingProvider->method('getPriority')->willReturn(100);
        $failingProvider->method('getPhase')->willReturn('post');
        $failingProvider->method('supports')->willReturn(true);
        $failingProvider->method('provide')->willThrowException(new \RuntimeException('Provider error'));

        // Working provider
        $workingProvider = $this->createMockProvider(
            'working',
            50,
            'post',
            true,
            new ToolIntelligence(suggestions: ['Working'])
        );

        $aggregator->registerProviders([$failingProvider, $workingProvider]);

        $result = ToolResult::success([]);
        $context = IntelligenceContext::preExecution('test', [])->withResult($result);

        // Should not throw, should continue with working provider
        $intelligence = $aggregator->gatherPost($context);

        $this->assertCount(1, $intelligence->suggestions);
        $this->assertEquals('Working', $intelligence->suggestions[0]);
    }

    public function testBuildIntelligentResultCreatesIntelligentResult(): void
    {
        $aggregator = new IntelligenceAggregator();

        $provider = $this->createMockProvider(
            'test',
            100,
            'post',
            true,
            new ToolIntelligence(suggestions: ['A suggestion'])
        );
        $aggregator->registerProvider($provider);

        $originalResult = ToolResult::success(['id' => 123, 'nombre' => 'Test']);
        $context = IntelligenceContext::postExecution(
            'create_note',
            ['nombre' => 'Test'],
            $originalResult,
            'Note',
            null,
            123
        );

        $intelligentResult = $aggregator->buildIntelligentResult(
            $originalResult,
            $context,
            'create_note'
        );

        $this->assertInstanceOf(IntelligentToolResult::class, $intelligentResult);
        $this->assertTrue($intelligentResult->success);
        $this->assertEquals('create_note', $intelligentResult->getAction());
        $this->assertStringContainsString('Crear', $intelligentResult->getAttempt());
    }

    public function testBuildIntelligentResultUsesCustomAttempt(): void
    {
        $aggregator = new IntelligenceAggregator();
        $result = ToolResult::success(['id' => 1]);
        $context = IntelligenceContext::postExecution('test', [], $result);

        $intelligentResult = $aggregator->buildIntelligentResult(
            $result,
            $context,
            'test',
            'Custom attempt description'
        );

        $this->assertEquals('Custom attempt description', $intelligentResult->getAttempt());
    }

    public function testGenerateAttemptDescriptionForCreateOperation(): void
    {
        $aggregator = new IntelligenceAggregator();

        $description = $aggregator->generateAttemptDescription('create_note', ['nombre' => 'Test']);

        $this->assertStringContainsString('Crear', $description);
        $this->assertStringContainsString('Note', $description);
        $this->assertStringContainsString('Test', $description);
    }

    public function testGenerateAttemptDescriptionForUpdateOperation(): void
    {
        $aggregator = new IntelligenceAggregator();

        $description = $aggregator->generateAttemptDescription('update_note', ['id' => 123]);

        $this->assertStringContainsString('Actualizar', $description);
        $this->assertStringContainsString('#123', $description);
    }

    public function testGenerateAttemptDescriptionForDeleteOperation(): void
    {
        $aggregator = new IntelligenceAggregator();

        $description = $aggregator->generateAttemptDescription('delete_user', ['id' => 456]);

        $this->assertStringContainsString('Eliminar', $description);
        $this->assertStringContainsString('#456', $description);
    }

    public function testGenerateAttemptDescriptionForGetOperation(): void
    {
        $aggregator = new IntelligenceAggregator();

        $description = $aggregator->generateAttemptDescription('get_brand', ['id' => 1]);

        $this->assertStringContainsString('Obtener', $description);
        $this->assertStringContainsString('#1', $description);
    }

    public function testGenerateAttemptDescriptionForListOperation(): void
    {
        $aggregator = new IntelligenceAggregator();

        $description = $aggregator->generateAttemptDescription('list_notes', []);

        $this->assertStringContainsString('Listar', $description);
    }

    public function testGenerateAttemptDescriptionForFindOperation(): void
    {
        $aggregator = new IntelligenceAggregator();

        $description = $aggregator->generateAttemptDescription('find_available_notes', []);

        $this->assertStringContainsString('Buscar', $description);
    }

    public function testGenerateAttemptDescriptionForUnknownOperation(): void
    {
        $aggregator = new IntelligenceAggregator();

        $description = $aggregator->generateAttemptDescription('custom_operation', []);

        $this->assertStringContainsString('Ejecutar', $description);
        $this->assertStringContainsString('custom_operation', $description);
    }

    public function testEmptyAggregatorReturnsEmptyIntelligence(): void
    {
        $aggregator = new IntelligenceAggregator();

        $context = IntelligenceContext::preExecution('test', []);
        $intelligence = $aggregator->gatherPost($context);

        $this->assertTrue($intelligence->isEmpty());
    }
}
