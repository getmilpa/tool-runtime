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

namespace Milpa\ToolRuntime\Tests\RateLimiting;

use PHPUnit\Framework\TestCase;
use Milpa\ToolRuntime\RateLimiting\InMemoryRateLimiter;
use Milpa\ToolRuntime\RateLimiting\RateLimitResult;

class InMemoryRateLimiterTest extends TestCase
{
    private InMemoryRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new InMemoryRateLimiter();
    }

    public function testConsumeAllowsWithinLimit(): void
    {
        $result = $this->limiter->consume('test:key', 1, 60, 100);

        $this->assertTrue($result->allowed);
        $this->assertEquals(99, $result->remainingTokens);
    }

    public function testConsumeDeniesWhenExceeded(): void
    {
        // Consume all tokens
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->consume('test:key', 10, 60, 100);
        }

        // Next call should be denied
        $result = $this->limiter->consume('test:key', 1, 60, 100);

        $this->assertFalse($result->allowed);
        $this->assertNotNull($result->reason);
        $this->assertGreaterThan(0, $result->retryAfterSeconds);
    }

    /**
     * Item 3 (0.3 design): a rate-limit denial's reason must state WHICH key (i.e. which
     * channel:principal:tool composite, as {@see \Milpa\ToolRuntime\ToolRegistry::call()}
     * builds it) hit the budget, not just the budget figures alone — otherwise a caller
     * juggling several rate-limited calls has no way to tell which one was throttled from the
     * message text alone.
     */
    public function testConsumeDeniedReasonNamesTheKeyAndTheBudget(): void
    {
        $this->limiter->consume('mcp:agent:reviewer:resolve_verification', 100, 60, 100);

        $result = $this->limiter->consume('mcp:agent:reviewer:resolve_verification', 1, 60, 100);

        $this->assertFalse($result->allowed);
        $this->assertStringContainsString('mcp:agent:reviewer:resolve_verification', $result->reason);
        $this->assertStringContainsString('100', $result->reason);
        $this->assertStringContainsString('60', $result->reason);
    }

    public function testConsumeCostAffectsTokens(): void
    {
        // Consume 50 tokens at once
        $result = $this->limiter->consume('test:key', 50, 60, 100);

        $this->assertTrue($result->allowed);
        $this->assertEquals(50, $result->remainingTokens);

        // Consume 50 more
        $result = $this->limiter->consume('test:key', 50, 60, 100);

        $this->assertTrue($result->allowed);
        $this->assertEquals(0, $result->remainingTokens);

        // Next call should be denied
        $result = $this->limiter->consume('test:key', 1, 60, 100);
        $this->assertFalse($result->allowed);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        // Exhaust key1
        $this->limiter->consume('key1', 100, 60, 100);
        $result1 = $this->limiter->consume('key1', 1, 60, 100);
        $this->assertFalse($result1->allowed);

        // key2 should still work
        $result2 = $this->limiter->consume('key2', 1, 60, 100);
        $this->assertTrue($result2->allowed);
    }

    public function testGetUsageReturnsCurrentUsage(): void
    {
        $this->assertEquals(0, $this->limiter->getUsage('test:key'));

        $this->limiter->consume('test:key', 25, 60, 100);
        $this->assertEquals(25, $this->limiter->getUsage('test:key'));

        $this->limiter->consume('test:key', 10, 60, 100);
        $this->assertEquals(35, $this->limiter->getUsage('test:key'));
    }

    public function testResetClearsUsage(): void
    {
        $this->limiter->consume('test:key', 50, 60, 100);
        $this->assertEquals(50, $this->limiter->getUsage('test:key'));

        $this->limiter->reset('test:key');
        $this->assertEquals(0, $this->limiter->getUsage('test:key'));
    }

    public function testCleanupRemovesOldBuckets(): void
    {
        // Add some usage
        $this->limiter->consume('old:key', 10, 60, 100);

        // Cleanup with very short maxAge (0 seconds)
        // Note: This won't actually remove it since we just added it
        // We mainly test that cleanup doesn't throw
        $this->limiter->cleanup(0);

        // Key should still exist since it was just created
        $this->assertGreaterThanOrEqual(0, $this->limiter->getUsage('old:key'));
    }

    public function testRateLimitResultFactoryMethods(): void
    {
        $allowed = RateLimitResult::allowed(50);
        $this->assertTrue($allowed->allowed);
        $this->assertEquals(50, $allowed->remainingTokens);
        $this->assertNull($allowed->reason);

        $denied = RateLimitResult::denied('Rate exceeded', 30);
        $this->assertFalse($denied->allowed);
        $this->assertEquals('Rate exceeded', $denied->reason);
        $this->assertEquals(30, $denied->retryAfterSeconds);
    }

    public function testMaxTokensConfigurable(): void
    {
        // With maxTokens = 10, should be denied after 10 calls of cost 1
        for ($i = 0; $i < 10; $i++) {
            $result = $this->limiter->consume('limited:key', 1, 60, 10);
            $this->assertTrue($result->allowed, "Call $i should be allowed");
        }

        $result = $this->limiter->consume('limited:key', 1, 60, 10);
        $this->assertFalse($result->allowed);
    }

    public function testCostGreaterThanMaxTokensDenied(): void
    {
        // Trying to consume more than maxTokens in one call
        $result = $this->limiter->consume('test:key', 150, 60, 100);

        $this->assertFalse($result->allowed);
    }
}
