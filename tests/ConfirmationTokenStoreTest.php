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
use Milpa\ToolRuntime\ConfirmationTokenStore;
use Milpa\ToolRuntime\Confirmation\ConfirmationToken;

class ConfirmationTokenStoreTest extends TestCase
{
    private ConfirmationTokenStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ConfirmationTokenStore();
    }

    public function testCreateGeneratesUniqueToken(): void
    {
        $token1 = $this->store->create('delete_user', ['id' => 1], 'Delete user #1');
        $token2 = $this->store->create('delete_user', ['id' => 2], 'Delete user #2');

        $this->assertNotEquals($token1->token, $token2->token);
        $this->assertEquals(32, strlen($token1->token)); // 16 bytes = 32 hex chars
    }

    public function testCreateReturnsConfirmationToken(): void
    {
        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user #1');

        $this->assertInstanceOf(ConfirmationToken::class, $token);
        $this->assertEquals('Delete user #1', $token->actionSummary);
        $this->assertInstanceOf(\DateTimeImmutable::class, $token->expiresAt);
    }

    public function testConsumeReturnsOriginalArgs(): void
    {
        $originalArgs = ['id' => 1, 'force' => true];
        $token = $this->store->create('delete_user', $originalArgs, 'Delete user');

        $args = $this->store->consume($token->token, 'delete_user');

        $this->assertEquals($originalArgs, $args);
    }

    public function testConsumeInvalidatesToken(): void
    {
        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');

        // First consume should succeed
        $args1 = $this->store->consume($token->token, 'delete_user');
        $this->assertNotNull($args1);

        // Second consume should fail (token already consumed)
        $args2 = $this->store->consume($token->token, 'delete_user');
        $this->assertNull($args2);
    }

    public function testConsumeWithWrongToolReturnsNull(): void
    {
        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');

        $args = $this->store->consume($token->token, 'delete_post'); // Wrong tool

        $this->assertNull($args);
    }

    public function testConsumeWithInvalidTokenReturnsNull(): void
    {
        $args = $this->store->consume('invalid_token_abc123', 'delete_user');

        $this->assertNull($args);
    }

    public function testIsValidReturnsTrueForValidToken(): void
    {
        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');

        $isValid = $this->store->isValid($token->token, 'delete_user');

        $this->assertTrue($isValid);
    }

    public function testIsValidReturnsFalseForWrongTool(): void
    {
        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');

        $isValid = $this->store->isValid($token->token, 'delete_post');

        $this->assertFalse($isValid);
    }

    public function testIsValidReturnsFalseForInvalidToken(): void
    {
        $isValid = $this->store->isValid('invalid_token', 'delete_user');

        $this->assertFalse($isValid);
    }

    public function testIsValidDoesNotConsumeToken(): void
    {
        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');

        // Check validity multiple times
        $this->assertTrue($this->store->isValid($token->token, 'delete_user'));
        $this->assertTrue($this->store->isValid($token->token, 'delete_user'));
        $this->assertTrue($this->store->isValid($token->token, 'delete_user'));

        // Token should still be consumable
        $args = $this->store->consume($token->token, 'delete_user');
        $this->assertNotNull($args);
    }

    public function testExpiredTokenReturnsNullOnConsume(): void
    {
        // Set TTL to 1 second
        $this->store->setTtl(1);

        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');

        // Wait for token to expire
        sleep(2);

        $args = $this->store->consume($token->token, 'delete_user');

        $this->assertNull($args);
    }

    public function testExpiredTokenIsInvalid(): void
    {
        $this->store->setTtl(1);

        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');

        sleep(2);

        $isValid = $this->store->isValid($token->token, 'delete_user');

        $this->assertFalse($isValid);
    }

    public function testSetTtlAffectsNewTokens(): void
    {
        $this->store->setTtl(3600); // 1 hour

        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');

        // Token should expire in approximately 1 hour
        $expectedExpiry = time() + 3600;
        $actualExpiry = $token->expiresAt->getTimestamp();

        $this->assertEqualsWithDelta($expectedExpiry, $actualExpiry, 2);
    }

    public function testCleanupRemovesExpiredTokens(): void
    {
        $this->store->setTtl(1);

        // Create multiple tokens
        $token1 = $this->store->create('tool1', ['id' => 1], 'Action 1');
        $token2 = $this->store->create('tool2', ['id' => 2], 'Action 2');

        sleep(2);

        // Create a new token (triggers cleanup)
        $this->store->setTtl(60);
        $token3 = $this->store->create('tool3', ['id' => 3], 'Action 3');

        // Expired tokens should be invalid
        $this->assertFalse($this->store->isValid($token1->token, 'tool1'));
        $this->assertFalse($this->store->isValid($token2->token, 'tool2'));

        // New token should be valid
        $this->assertTrue($this->store->isValid($token3->token, 'tool3'));
    }

    public function testTokenExpiresAtIsCorrect(): void
    {
        $this->store->setTtl(120); // 2 minutes

        $beforeCreate = time();
        $token = $this->store->create('delete_user', ['id' => 1], 'Delete user');
        $afterCreate = time();

        $expiresAt = $token->expiresAt->getTimestamp();

        $this->assertGreaterThanOrEqual($beforeCreate + 120, $expiresAt);
        $this->assertLessThanOrEqual($afterCreate + 120, $expiresAt);
    }
}
