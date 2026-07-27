<?php

/**
 * This file is part of Milpa Tool Runtime — the tool registry, policy and
 * rendering layer of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/tool-runtime
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use Milpa\ToolRuntime\TokenEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What decides how many tools an agent is allowed to carry into a call.
 *
 * Nothing had ever executed it. Get the model limit wrong and either the host
 * ships tools the model will reject, or it silently drops tools the model had
 * room for — and both failures look like "the agent didn't use my tool".
 */
#[CoversClass(TokenEstimator::class)]
final class TokenEstimatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return array{name: string, description: string, inputSchema: array<mixed>}
     */
    private function tool(string $name, string $description = 'Hace algo', array $schema = ['type' => 'object']): array
    {
        return ['name' => $name, 'description' => $description, 'inputSchema' => $schema];
    }

    // ---- estimating ------------------------------------------------------------

    public function testTokensAreEstimatedAtFourCharactersEachRoundedUp(): void
    {
        $estimator = new TokenEstimator();

        self::assertSame(0, $estimator->estimateTokens(''));
        self::assertSame(1, $estimator->estimateTokens('abc'), 'Three characters still cost a whole token.');
        self::assertSame(1, $estimator->estimateTokens('abcd'));
        self::assertSame(2, $estimator->estimateTokens('abcde'));
        self::assertSame(25, $estimator->estimateTokens(str_repeat('x', 100)));
    }

    public function testAToolCostsWhatItsWholeJsonDefinitionCosts(): void
    {
        // The model is charged for the schema too, not just the description. A
        // count that ignored it would under-report the expensive tools most.
        $estimator = new TokenEstimator();

        $simple = $estimator->estimateToolTokens($this->tool('ping'));
        $rich = $estimator->estimateToolTokens($this->tool('ping', 'Hace algo', [
            'type' => 'object',
            'properties' => ['host' => ['type' => 'string', 'description' => 'El host a consultar']],
        ]));

        self::assertGreaterThan($simple, $rich);
    }

    public function testEveryToolIsCountedAndNamedInTheBreakdown(): void
    {
        $estimator = new TokenEstimator();

        $estimate = $estimator->estimateToolsTokens([$this->tool('uno'), $this->tool('dos')]);

        self::assertArrayHasKey('uno', $estimate['per_tool']);
        self::assertArrayHasKey('dos', $estimate['per_tool']);
        self::assertSame(
            $estimate['per_tool']['uno'] + $estimate['per_tool']['dos'],
            $estimate['total'],
            'The total is the sum of its parts, not a separate estimate.',
        );
    }

    public function testNoToolsCostNothing(): void
    {
        $estimate = (new TokenEstimator())->estimateToolsTokens([]);

        self::assertSame(0, $estimate['total']);
        self::assertSame([], $estimate['per_tool']);
    }

    // ---- model limits -------------------------------------------------------------

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function models(): iterable
    {
        yield 'gpt-3.5-turbo' => ['gpt-3.5-turbo', 4096];
        yield 'gpt-4' => ['gpt-4', 8192];
        yield 'gpt-4-32k' => ['gpt-4-32k', 32768];
        yield 'gpt-4-turbo' => ['gpt-4-turbo', 128000];
        yield 'gpt-4o' => ['gpt-4o', 128000];
        yield 'claude-2' => ['claude-2', 100000];
        yield 'claude-3-opus' => ['claude-3-opus', 200000];
        yield 'claude-sonnet' => ['claude-sonnet', 200000];
        yield 'claude-haiku' => ['claude-haiku', 200000];
        yield 'claude-opus' => ['claude-opus', 200000];
    }

    #[DataProvider('models')]
    public function testAKnownModelGetsItsOwnContextLimit(string $model, int $limit): void
    {
        self::assertSame($limit, (new TokenEstimator())->getModelLimit($model));
    }

    public function testADatedModelNameMatchesOnItsPrefix(): void
    {
        // Providers append a release date to the id. Without the prefix match
        // every real-world model name would fall to the conservative default.
        $estimator = new TokenEstimator();

        self::assertSame(128000, $estimator->getModelLimit('gpt-4o-2024-05-13'));
        self::assertSame(200000, $estimator->getModelLimit('claude-sonnet-5-20260101'));
    }

    public function testAModelNobodyListedFallsBackToTheConservativeLimit(): void
    {
        // Guessing high here is how a host ships a tool set the model rejects
        // outright. The floor is the safe direction to be wrong in.
        self::assertSame(8192, (new TokenEstimator())->getModelLimit('un-modelo-que-no-existe'));
    }

    public function testTheToolBudgetIsThirtyPercentOfTheContext(): void
    {
        // The other 70% is the system prompt, the conversation and the answer.
        // A budget of 100% would leave no room to reply.
        $estimator = new TokenEstimator();

        self::assertSame(1228, $estimator->getToolBudget('gpt-3.5-turbo'), '4096 * 30%, redondeado hacia abajo.');
        self::assertSame(60000, $estimator->getToolBudget('claude-opus'));
    }

    // ---- fitting -----------------------------------------------------------------------

    public function testASmallToolSetFitsAndSaysByHowMuch(): void
    {
        $check = (new TokenEstimator())->checkToolBudget([$this->tool('ping')], 'claude-opus');

        self::assertTrue($check['fits']);
        self::assertSame(0, $check['overflow']);
        self::assertSame(60000, $check['budget']);
        self::assertSame(200000, $check['model_limit']);
        self::assertLessThan(1.0, $check['percent']);
    }

    public function testAToolSetThatDoesNotFitReportsTheOverflowInTokens(): void
    {
        // "Too big" is not actionable; "over by N tokens" tells the host how
        // much to drop.
        $tools = array_map(
            fn (int $i): array => $this->tool('tool' . $i, str_repeat('descripción larga ', 200)),
            range(1, 20),
        );

        $check = (new TokenEstimator())->checkToolBudget($tools, 'gpt-3.5-turbo');

        self::assertFalse($check['fits']);
        self::assertGreaterThan(0, $check['overflow']);
        self::assertSame($check['used'] - $check['budget'], $check['overflow']);
        self::assertGreaterThan(100.0, $check['percent']);
    }

    public function testSelectionKeepsToolsInOrderUntilTheBudgetRunsOutAndNamesTheRest(): void
    {
        // Order is the priority: the host lists what matters first, and what
        // gets dropped has to be named so it can say why the tool is missing.
        $grande = str_repeat('descripción larga ', 200);
        $tools = [
            $this->tool('primera', $grande),
            $this->tool('segunda', $grande),
            $this->tool('tercera', $grande),
            $this->tool('cuarta', $grande),
        ];

        $selection = (new TokenEstimator())->selectToolsWithinBudget($tools, 'gpt-3.5-turbo');

        self::assertNotSame([], $selection['selected']);
        self::assertNotSame([], $selection['excluded']);
        self::assertSame('primera', $selection['selected'][0]['name'], 'The first listed is the first kept.');
        self::assertSame('cuarta', $selection['excluded'][count($selection['excluded']) - 1]);
        self::assertLessThanOrEqual($selection['budget'], $selection['tokens_used']);
        self::assertCount(4, array_merge($selection['selected'], $selection['excluded']), 'Nothing is lost between the two lists.');
    }

    public function testWhenEverythingFitsNothingIsExcluded(): void
    {
        $selection = (new TokenEstimator())->selectToolsWithinBudget(
            [$this->tool('uno'), $this->tool('dos')],
            'claude-opus',
        );

        self::assertCount(2, $selection['selected']);
        self::assertSame([], $selection['excluded']);
    }

    public function testASingleToolTooBigForTheWholeBudgetIsExcludedRatherThanTruncated(): void
    {
        $selection = (new TokenEstimator())->selectToolsWithinBudget(
            [$this->tool('enorme', str_repeat('x', 100000))],
            'gpt-3.5-turbo',
        );

        self::assertSame([], $selection['selected']);
        self::assertSame(['enorme'], $selection['excluded']);
        self::assertSame(0, $selection['tokens_used']);
    }

    // ---- the report ------------------------------------------------------------------------

    public function testTheReportNamesTheModelTheBudgetAndTheVerdict(): void
    {
        $report = (new TokenEstimator())->getUsageReport([$this->tool('ping')], 'claude-opus');

        self::assertStringContainsString('Model: claude-opus', $report);
        self::assertStringContainsString('Context Limit: 200000 tokens', $report);
        self::assertStringContainsString('Tool Budget (30%): 60000 tokens', $report);
        self::assertStringContainsString('Tools Registered: 1', $report);
        self::assertStringContainsString('Within budget', $report);
        self::assertStringContainsString('- ping:', $report);
    }

    public function testTheReportSaysByHowMuchWhenItIsOverBudget(): void
    {
        $tools = array_map(
            fn (int $i): array => $this->tool('tool' . $i, str_repeat('descripción larga ', 200)),
            range(1, 20),
        );

        $report = (new TokenEstimator())->getUsageReport($tools, 'gpt-3.5-turbo');

        self::assertStringContainsString('OVER BUDGET by', $report);
    }

    public function testTheReportShowsTheTenBiggestToolsAndStopsThere(): void
    {
        // A registry with hundreds of tools would otherwise print hundreds of
        // lines, and the point of the report is to name the expensive few.
        $tools = array_map(
            fn (int $i): array => $this->tool('tool' . $i, str_repeat('x', $i * 20)),
            range(1, 25),
        );

        $report = (new TokenEstimator())->getUsageReport($tools, 'claude-opus');
        $listed = substr_count($report, "\n  - ");

        self::assertSame(10, $listed);
        self::assertStringContainsString('- tool25:', $report, 'The biggest one is listed.');
        self::assertStringNotContainsString('- tool1:', $report, 'The smallest one is not.');
    }
}
