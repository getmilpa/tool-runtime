<?php

/** Active withdrawal is distinct from historical refusal and catalogue hiding (greenhouse0362/0679).
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests\Gate;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\{GatedToolCalls, ToolCallGate, ToolCallRecorder, ToolCallRefused};
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ActiveWithdrawalTest extends TestCase
{
    private function door(): array
    {
        $state = (object)['handled' => 0,'judged' => 0,'records' => [],'withdrawn' => [],'hidden' => [],'historical' => false];
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('mark', 'Record a witness', ['type' => 'object'], static function () use ($state): array {
            ++$state->handled;
            return ['marked' => true];
        }, new ToolOptions(scopes:['mark:write']));
        $gate = new class ($state) implements ToolCallGate {
            public function __construct(private object $state)
            {
            }
            public function refuse(string $tool, array $arguments): ?string
            {
                ++$this->state->judged;
                return null;
            }
        };
        $recorder = new class ($state) implements ToolCallRecorder {
            public function __construct(private object $state)
            {
            }
            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->state->records[] = [$tool,$arguments,$result,$ok];
            }
        };
        $door = new class ($registry, $gate, $recorder, $state) extends GatedToolCalls {
            public function __construct(ToolRegistry $r, ToolCallGate $g, ToolCallRecorder $c, private object $state)
            {
                parent::__construct($r, $g, $c);
            }
            protected function withdrawn(): array
            {
                return $this->state->withdrawn;
            }
            protected function hidden(): array
            {
                return $this->state->hidden;
            }
            protected function optionRemoved(string $tool): bool
            {
                return $this->state->historical;
            }
        };
        $door->setContext(new ToolContext(principal:'fixture', channel:'lab', scopes:['mark:write']));
        return [$door,$state];
    }
    public function testActiveWithdrawalRejectsBeforeGateAndHandlerAndRecordsFailure(): void
    {
        [$door,$state] = $this->door();
        $state->withdrawn = ['mark'];
        try {
            $door->callTool('mark', []);
            self::fail('A withdrawn call executed');
        } catch (ToolCallRefused $e) {
            self::assertTrue($e->optionRemoved);
            self::assertSame("Tool 'mark' has been withdrawn from this session.", $e->getMessage());
        }
        self::assertSame(0, $state->judged);
        self::assertSame(0, $state->handled);
        self::assertSame([['mark',[],"Tool 'mark' has been withdrawn from this session.",false]], $state->records);
    }
    public function testScopeRefusalKeepsPriorityOverWithdrawal(): void
    {
        [$door,$state] = $this->door();
        $state->withdrawn = ['mark'];
        $door->setContext(new ToolContext(principal:'fixture', channel:'lab', scopes:['read']));
        try {
            $door->callTool('mark', []);
            self::fail('Out-of-scope call executed');
        } catch (ToolCallRefused) {
            self::fail('Scope refusal was replaced by withdrawal');
        } catch (\Exception $e) {
            self::assertStringContainsString('Missing required scope', $e->getMessage());
        }
        self::assertSame(0, $state->judged);
        self::assertSame(0, $state->handled);
        self::assertFalse($state->records[0][3]);
    }
    public function testVisibilityAndHistoryDoNotInventAnActiveWithdrawal(): void
    {
        [$door,$state] = $this->door();
        $state->hidden = ['mark'];
        $state->historical = true;
        self::assertSame([], $door->getToolSummaries());
        self::assertSame(['marked' => true], $door->callTool('mark', []));
        self::assertSame(1, $state->handled);
        self::assertSame(1, $state->judged);
    }
    public function testWithdrawalIsReadAgainForEachCall(): void
    {
        [$door,$state] = $this->door();
        self::assertSame(['marked' => true], $door->callTool('mark', []));
        $state->withdrawn = ['mark'];
        try {
            $door->callTool('mark', []);
            self::fail('New withdrawal was ignored');
        } catch (ToolCallRefused) {
        }
        $state->withdrawn = [];
        self::assertSame(['marked' => true],$door->callTool('mark',[]));
        self::assertSame(2,$state->handled);
    }
}
