<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests\Gate;

use Milpa\ToolRuntime\Contracts\ContextualToolHandler;
use Milpa\ToolRuntime\Contracts\ResultBudget;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** A budget follows the governed dispatch and cannot survive its synchronous call. */
final class ResultBudgetCallTest extends TestCase
{
    public function testOverrideCanReplaceContextWithoutLosingBudgetAndRecorderStillRuns(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $seen = $this->reader($registry);
        $recorder = new class () implements ToolCallRecorder {
            public array $calls = [];
            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->calls[] = [$tool, $result, $ok];
            }
        };
        $door = new class ($registry, null, $recorder) extends GatedToolCalls {
            public int $dispatches = 0;
            public function callTool(string $name, array $args): mixed
            {
                ++$this->dispatches;
                $this->setContext(new ToolContext('verified', 'mcp', ['read'], 'correlation', extra: ['consent' => 'kept']));
                return parent::callTool($name, $args);
            }
        };
        $budget = ResultBudget::json(6144);
        self::assertSame('read', $door->callToolWithBudget('read', [], $budget));
        self::assertSame('read', $door->callTool('read', []));
        self::assertSame(2, $door->dispatches);
        self::assertSame($budget, $seen->contexts[0]->resultBudget);
        self::assertNull($seen->contexts[1]->resultBudget);
        self::assertSame($seen->contexts[0]->toArray(), $seen->contexts[1]->toArray());
        self::assertSame(['consent' => 'kept'], $seen->contexts[0]->extra);
        self::assertSame([['read', 'read', true], ['read', 'read', true]], $recorder->calls);
    }

    public function testFailureAndGateRefusalRestoreTheFollowingCall(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $seen = $this->reader($registry);
        $registry->register('fail', 'Throws', ['type' => 'object'], static fn (): never => throw new \RuntimeException('fixture failure'));
        $gate = new class () implements ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return $tool === 'denied' ? 'fixture refusal' : null;
            }
        };
        $door = new GatedToolCalls($registry, $gate);
        foreach (['fail', 'denied'] as $name) {
            try {
                $door->callToolWithBudget($name, [], ResultBudget::json(1000));
                self::fail('The call must fail.');
            } catch (\Exception $error) {
                self::assertStringContainsString('fixture', $error->getMessage());
                self::assertSame($name === 'denied', $error instanceof ToolCallRefused);
            }
            $door->callTool('read', []);
        }
        self::assertCount(2, $seen->contexts);
        self::assertNull($seen->contexts[0]->resultBudget);
        self::assertNull($seen->contexts[1]->resultBudget);
    }

    public function testBudgetCannotBuyScopeOrExecuteAPlan(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $seen = $this->reader($registry);
        $door = new GatedToolCalls($registry);
        $door->setContext(new ToolContext('outsider', 'mcp', []));
        try {
            $door->callToolWithBudget('read', ['max_chars' => 999999], ResultBudget::json(999999));
            self::fail('A budget is not a scope grant.');
        } catch (\Exception $error) {
            self::assertStringContainsString('Missing required scope', $error->getMessage());
        }
        $door->setContext(new ToolContext('reader', 'mcp', ['read'], mode: 'plan'));
        $door->callToolWithBudget('read', [], ResultBudget::json(1000));
        self::assertSame([], $seen->contexts);
        $door->setContext(new ToolContext('reader', 'mcp', ['read']));
        $door->callTool('read', []);
        self::assertNull($seen->contexts[0]->resultBudget);
    }

    public function testNestedBudgetRestoresOuterBudgetAndThenDefault(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $seen = $this->reader($registry);
        $door = new GatedToolCalls($registry);
        $outer = ResultBudget::json(2000);
        $inner = ResultBudget::json(1000);
        $registry->register('outer', 'Nested calls', ['type' => 'object'], static function () use ($door, $inner): string {
            $door->callToolWithBudget('read', [], $inner);
            $door->callTool('read', []);
            return 'done';
        });
        $door->callToolWithBudget('outer', [], $outer);
        $door->callTool('read', []);
        self::assertSame([$inner, $outer, null], array_map(static fn (ToolContext $c) => $c->resultBudget, $seen->contexts));
    }

    private function reader(ToolRegistry $registry): ContextualToolHandler
    {
        $handler = new class () implements ContextualToolHandler {
            public array $contexts = [];
            public function __invoke(array $arguments, ToolContext $context): mixed
            {
                $this->contexts[] = $context;
                return 'read';
            }
        };
        $registry->register('read', 'Read', ['type' => 'object'], $handler, new ToolOptions(scopes: ['read']));
        return $handler;
    }
}
