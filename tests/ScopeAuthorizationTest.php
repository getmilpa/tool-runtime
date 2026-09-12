<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Scope admission is shared with surfaces and occurs before session consent (greenhouse 0314). */
final class ScopeAuthorizationTest extends TestCase
{
    /** @return iterable<string, array{list<string>, list<string>, bool}> */
    public static function scopeCases(): iterable
    {
        yield 'matching alternative' => [['posts:read'], ['posts:write', 'posts:read'], true];
        yield 'wildcard' => [['*'], ['posts:write'], true];
        yield 'undeclared requirement' => [[], [], true];
        yield 'empty identity scopes' => [[], ['posts:read'], false];
        yield 'wrong scope' => [['files:read'], ['posts:read'], false];
        yield 'no invented prefix wildcard' => [['posts:*'], ['posts:read'], false];
    }

    /** @param list<string> $scopes @param list<string> $required */
    #[DataProvider('scopeCases')]
    public function testTheScopeVerdictMatchesFullAuthorizationForARead(array $scopes, array $required, bool $allowed): void
    {
        $ctx = new ToolContext(principal: 'developer', channel: 'cli', scopes: $scopes);
        $gate = new PolicyGate();
        $tool = new ToolDefinition('posts_read', 'Read posts', [], static fn (): null => null, scopes: $required);
        $scope = $gate->authorizeScopes($ctx, $tool->name, $tool->scopes);
        $full = $gate->authorize($ctx, $tool);

        self::assertSame($allowed, $scope->allowed);
        self::assertEquals($full, $scope);
    }

    /** Missing scope must not ask consent, even when that consent gate would admit the call. */
    public function testTheDoorRefusesScopeBeforeAskingItsSessionGateAndRecordsTheFailure(): void
    {
        $gate = new class () implements ToolCallGate {
            public int $calls = 0;

            public function refuse(string $tool, array $arguments): ?string
            {
                ++$this->calls;

                return 'would open a consent question';
            }
        };
        $recorder = new class () implements ToolCallRecorder {
            /** @var list<array{string, array<string, mixed>, string, bool}> */
            public array $calls = [];

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->calls[] = [$tool, $arguments, $result, $ok];
            }
        };
        $executed = 0;
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('posts_write', 'Write a post', ['type' => 'object'], function () use (&$executed): int {
            return ++$executed;
        }, new ToolOptions(scopes: ['posts:write'], mutating: true, requiresConfirmation: true));
        $door = new GatedToolCalls($registry, $gate, $recorder);
        $door->setContext(new ToolContext(principal: 'developer', channel: 'cli', scopes: ['posts:read']));

        try {
            $door->callTool('posts_write', []);
            self::fail('an out-of-scope mutation must not run');
        } catch (\Exception $error) {
            self::assertSame("Missing required scope for tool 'posts_write'. Need one of: posts:write — context has: posts:read.", $error->getMessage());
            self::assertSame([['posts_write', [], $error->getMessage(), false]], $recorder->calls);
        }
        self::assertSame(0, $gate->calls);
        self::assertSame(0, $executed);

        // Positive control: supplying scope reaches the SAME session gate, which still refuses.
        $door->setContext(new ToolContext(principal: 'developer', channel: 'cli', scopes: ['posts:write']));
        try {
            $door->callTool('posts_write', []);
            self::fail('scope does not replace consent');
        } catch (\Exception $error) {
            self::assertSame('would open a consent question', $error->getMessage());
        }
        self::assertSame(1, $gate->calls);
        self::assertSame(0, $executed);
    }
}
