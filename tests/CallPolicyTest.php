<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolDefinition;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Resource authority must precede consent and survive the registry's execution policy. */
final class CallPolicyTest extends TestCase
{
    public function testArgumentsReachTheSamePolicyBeforeConsentAndDirectExecution(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('write', '', ['type' => 'object'], static fn (): string => 'written');
        $policy = new class () implements CallPolicy {
            public int $calls = 0;
            public function authorize(ToolContext $context, ToolDefinition $tool, array $arguments): AuthorizationResult
            {
                ++$this->calls;
                return ($arguments['plugin'] ?? '') === 'Owned'
                    ? AuthorizationResult::allowed() : AuthorizationResult::denied('outside plugin');
            }
        };
        $registry->getPolicyGate()->setCallPolicy($policy);
        $gate = $this->createMock(ToolCallGate::class);
        $gate->expects(self::once())->method('refuse')->with('write', ['plugin' => 'Owned'])->willReturn(null);
        $door = new GatedToolCalls($registry, $gate);
        $context = new ToolContext(principal: 'worker', channel: 'web', scopes: []);
        $door->setContext($context);
        try {
            $door->callTool('write', ['plugin' => 'Other']);
            self::fail('resource denial must occur before consent');
        } catch (\Exception $error) {
            self::assertSame('outside plugin', $error->getMessage());
        }
        self::assertSame('outside plugin', $registry->call('write', ['plugin' => 'Other'], $context)->error);
        self::assertSame('written', $door->callTool('write', ['plugin' => 'Owned']));
        self::assertSame(4, $policy->calls);
    }

    public function testHostPolicyCannotOverrideStaticScopeDenial(): void
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('write', '', [], static fn (): null => null, new ToolOptions(scopes: ['write']));
        $policy = $this->createMock(CallPolicy::class);
        $policy->expects(self::never())->method('authorize');
        $registry->getPolicyGate()->setCallPolicy($policy);
        $result = $registry->call('write', [], new ToolContext(principal: 'worker', channel: 'web'));
        self::assertFalse($result->success);
        self::assertStringContainsString('Missing required scope', (string) $result->error);
    }
}
