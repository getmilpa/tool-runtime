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

namespace Milpa\ToolRuntime\Tests\Gate;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The gate is consulted before, the recorder told after, and a refusal is its own kind of failure
 * (greenhouse decisions/0225).
 */
final class GatedToolCallsTest extends TestCase
{
    private function registry(): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('echo', 'Says back what it got', ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]], static fn (array $a): ToolResult => new ToolResult(true, ['said' => $a['text'] ?? '']));
        $registry->register('fail', 'Always fails', ['type' => 'object'], static fn (array $a): ToolResult => new ToolResult(false, error: 'no way'));
        $registry->register('secret', 'Hidden by an extension', ['type' => 'object'], static fn (array $a): ToolResult => new ToolResult(true, 'shh'));

        return $registry;
    }

    public function testWithoutAGateACallRunsAsItRanAndTheRecorderIsToldOnce(): void
    {
        $told = [];
        $recorder = new class ($told) implements ToolCallRecorder {
            /** @param list<array<string, mixed>> $told */
            public function __construct(private array &$told)
            {
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->told[] = [$tool, $arguments, $result, $ok];
            }
        };
        $calls = new GatedToolCalls($this->registry(), null, $recorder);

        self::assertSame(['said' => 'hi'], $calls->callTool('echo', ['text' => 'hi']));
        self::assertSame([['echo', ['text' => 'hi'], '{"said":"hi"}', true]], $told, 'an array result is recorded as JSON, not as «Array»');

        try {
            $calls->callTool('fail', []);
            self::fail('a failing tool throws');
        } catch (ToolCallRefused) {
            self::fail('a tool failure is NOT a refusal');
        } catch (\Exception $e) {
            self::assertSame('no way', $e->getMessage());
        }
        self::assertSame(['fail', [], 'no way', false], $told[1]);
    }

    public function testTheGateIsAskedFirstAndARefusalEndsTheCallWithItsReason(): void
    {
        $asked = [];
        $gate = new class ($asked) implements ToolCallGate {
            /** @param list<string> $asked */
            public function __construct(private array &$asked)
            {
            }

            public function refuse(string $tool, array $arguments): ?string
            {
                $this->asked[] = $tool;

                return $tool === 'fail' ? 'not in this session' : null;
            }
        };
        $calls = new GatedToolCalls($this->registry(), $gate);

        self::assertSame(['said' => 'ok'], $calls->callTool('echo', ['text' => 'ok']), 'what the gate lets through runs');
        try {
            $calls->callTool('fail', []);
            self::fail('the gate refused: the tool must not even run');
        } catch (ToolCallRefused $refused) {
            self::assertSame('not in this session', $refused->getMessage());
            self::assertFalse($refused->optionRemoved, 'nothing was taken off the table here');
        }
        self::assertSame(['echo', 'fail'], $asked);
    }

    public function testAnExtensionHidesNamesFromTheCatalogueAndMarksARemovedOption(): void
    {
        $gate = new class () implements ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return 'refused';
            }
        };
        $extended = new class ($this->registry(), $gate) extends GatedToolCalls {
            protected function hidden(): array
            {
                return ['secret'];
            }

            protected function optionRemoved(string $tool): bool
            {
                return $tool === 'secret';
            }
        };
        $plain = new GatedToolCalls($this->registry(), $gate);

        self::assertSame(['echo', 'fail', 'secret'], array_column($plain->getToolSummaries(), 'name'), 'the control: nothing hidden by default');
        self::assertSame(['echo', 'fail'], array_column($extended->getToolSummaries(), 'name'));

        try {
            $extended->callTool('secret', []);
            self::fail('refused');
        } catch (ToolCallRefused $refused) {
            self::assertTrue($refused->optionRemoved);
        }
        try {
            $plain->callTool('secret', []);
            self::fail('refused');
        } catch (ToolCallRefused $refused) {
            self::assertFalse($refused->optionRemoved);
        }
    }

    public function testWhatIsRecordedKeepsTheShapeTheModelGatewayAlwaysRecorded(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('null', 'Returns null', ['type' => 'object'], static fn (array $a): ToolResult => new ToolResult(true, null));
        $registry->register('zero', 'Returns 0', ['type' => 'object'], static fn (array $a): ToolResult => new ToolResult(true, 0));
        $registry->register('bad', 'Returns what JSON cannot encode', ['type' => 'object'], static fn (array $a): ToolResult => new ToolResult(true, ['k' => "\xB1"]));
        $told = [];
        $recorder = new class ($told) implements ToolCallRecorder {
            /** @param list<string> $told */
            public function __construct(private array &$told)
            {
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->told[] = $result;
            }
        };
        $calls = new GatedToolCalls($registry, null, $recorder);
        $calls->callTool('null', []);
        $calls->callTool('zero', []);
        $calls->callTool('bad', []);

        // `null` as JSON, `0` as JSON, the unencodable as the empty string — never `NULL`, never `Array`.
        self::assertSame(['null', '0', ''], $told);
        self::assertNull(json_decode($told[0], true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testTheContextTravelsToTheRegistry(): void
    {
        $calls = new GatedToolCalls($this->registry());
        self::assertNull($calls->getContext());
        $context = new ToolContext(channel: 'cli');
        $calls->setContext($context);
        self::assertSame($context, $calls->getContext());
        self::assertSame(['said' => 'x'], $calls->callTool('echo', ['text' => 'x']));
    }
}
