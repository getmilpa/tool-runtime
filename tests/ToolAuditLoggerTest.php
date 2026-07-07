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

namespace Milpa\ToolRuntime\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\ToolRuntime\ToolAuditLogger;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Psr\Log\LoggerInterface;

class ToolAuditLoggerTest extends TestCase
{
    private ToolAuditLogger $auditLogger;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->auditLogger = new ToolAuditLogger($this->logger);
    }

    public function testLogSuccessfulCall(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: ['read'],
            request_id: 'req-abc123'
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) {
                return str_contains($message, '[ToolAudit]')
                    && str_contains($message, 'telegram')
                    && str_contains($message, 'test_tool')
                    && str_contains($message, 'OK');
            }));

        $this->auditLogger->log(
            $ctx,
            'test_tool',
            ['id' => 123],
            true,
            null,
            50,
            1024
        );
    }

    public function testLogFailedCall(): void
    {
        $ctx = new ToolContext(
            principal: 'user:456',
            channel: 'web',
            scopes: ['read']
        );

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->callback(function ($message) {
                return str_contains($message, '[ToolAudit]')
                    && str_contains($message, 'ERROR:VALIDATION_ERROR');
            }));

        $this->auditLogger->log(
            $ctx,
            'test_tool',
            ['id' => 123],
            false,
            'VALIDATION_ERROR',
            10
        );
    }

    public function testSanitizesPasswordField(): void
    {
        $ctx = ToolContext::cli();
        $loggedMessage = null;

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) use (&$loggedMessage) {
                $loggedMessage = $message;
                return true;
            }));

        $this->auditLogger->log(
            $ctx,
            'login',
            ['username' => 'john', 'password' => 'secret123'],
            true,
            null,
            20
        );

        $this->assertStringContainsString('username', $loggedMessage);
        $this->assertStringContainsString('john', $loggedMessage);
        $this->assertStringNotContainsString('secret123', $loggedMessage);
        $this->assertStringContainsString('***REDACTED***', $loggedMessage);
    }

    public function testSanitizesTokenField(): void
    {
        $ctx = ToolContext::cli();
        $loggedMessage = null;

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) use (&$loggedMessage) {
                $loggedMessage = $message;
                return true;
            }));

        $this->auditLogger->log(
            $ctx,
            'api_call',
            ['token' => 'bearer_abc123xyz'],
            true,
            null,
            20
        );

        $this->assertStringNotContainsString('bearer_abc123xyz', $loggedMessage);
        $this->assertStringContainsString('***REDACTED***', $loggedMessage);
    }

    public function testSanitizesApiKeyField(): void
    {
        $ctx = ToolContext::cli();
        $loggedMessage = null;

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) use (&$loggedMessage) {
                $loggedMessage = $message;
                return true;
            }));

        $this->auditLogger->log(
            $ctx,
            'external_api',
            ['api_key' => 'sk-12345', 'endpoint' => '/users'],
            true,
            null,
            50
        );

        $this->assertStringNotContainsString('sk-12345', $loggedMessage);
        $this->assertStringContainsString('endpoint', $loggedMessage);
        $this->assertStringContainsString('/users', $loggedMessage);
    }

    public function testSanitizesNestedSensitiveFields(): void
    {
        $ctx = ToolContext::cli();
        $loggedMessage = null;

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) use (&$loggedMessage) {
                $loggedMessage = $message;
                return true;
            }));

        $this->auditLogger->log(
            $ctx,
            'nested_call',
            [
                'user' => [
                    'name' => 'John',
                    'password' => 'nested_secret',
                ],
            ],
            true,
            null,
            30
        );

        $this->assertStringNotContainsString('nested_secret', $loggedMessage);
        $this->assertStringContainsString('John', $loggedMessage);
    }

    public function testLogAuthFailure(): void
    {
        $ctx = new ToolContext(
            principal: 'user:789',
            channel: 'telegram',
            scopes: []
        );

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->callback(function ($message) {
                return str_contains($message, 'AUTH_DENIED')
                    && str_contains($message, 'admin_tool')
                    && str_contains($message, 'Missing scope');
            }));

        $this->auditLogger->logAuthFailure($ctx, 'admin_tool', 'Missing scope: admin:write');
    }

    public function testLogValidationFailure(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'web',
            scopes: ['read']
        );

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->callback(function ($message) {
                return str_contains($message, 'VALIDATION_FAILED')
                    && str_contains($message, 'create_user')
                    && str_contains($message, 'email');
            }));

        $this->auditLogger->logValidationFailure(
            $ctx,
            'create_user',
            ['Missing required field: email', 'Invalid format: phone']
        );
    }

    public function testAddSensitiveField(): void
    {
        $ctx = ToolContext::cli();
        $loggedMessage = null;

        $this->auditLogger->addSensitiveField('credit_card');

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) use (&$loggedMessage) {
                $loggedMessage = $message;
                return true;
            }));

        $this->auditLogger->log(
            $ctx,
            'payment',
            ['amount' => 100, 'credit_card' => '4111111111111111'],
            true,
            null,
            100
        );

        $this->assertStringNotContainsString('4111111111111111', $loggedMessage);
        $this->assertStringContainsString('100', $loggedMessage);
    }

    public function testLogIncludesRequestId(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'api',
            scopes: ['read'],
            request_id: 'unique-req-id-12345'
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('unique-req-id-12345'));

        $this->auditLogger->log(
            $ctx,
            'test_tool',
            [],
            true,
            null,
            10
        );
    }

    public function testLogIncludesTimingInfo(): void
    {
        $ctx = ToolContext::cli();

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('150ms'));

        $this->auditLogger->log(
            $ctx,
            'slow_tool',
            [],
            true,
            null,
            150
        );
    }

    public function testLogWithAnonymousPrincipal(): void
    {
        $ctx = new ToolContext(
            principal: null,
            channel: 'public',
            scopes: []
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('anonymous'));

        $this->auditLogger->log(
            $ctx,
            'public_tool',
            [],
            true,
            null,
            5
        );
    }

    public function testSanitizationIsCaseInsensitive(): void
    {
        $ctx = ToolContext::cli();
        $loggedMessage = null;

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) use (&$loggedMessage) {
                $loggedMessage = $message;
                return true;
            }));

        $this->auditLogger->log(
            $ctx,
            'sensitive_test',
            ['PASSWORD' => 'upper_secret', 'Token' => 'token_value', 'secret' => 'lower_secret'],
            true,
            null,
            10
        );

        // Values should be redacted
        $this->assertStringNotContainsString('upper_secret', $loggedMessage);
        $this->assertStringNotContainsString('token_value', $loggedMessage);
        $this->assertStringNotContainsString('lower_secret', $loggedMessage);
        // Redacted marker should be present
        $this->assertStringContainsString('***REDACTED***', $loggedMessage);
    }

    // ========== Additional Tests for Coverage ==========

    public function testLogWithIpAddress(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'web',
            scopes: ['read'],
            ip: '192.168.1.100'
        );

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) {
                return str_contains($message, '[ToolAudit]')
                    && str_contains($message, 'web')
                    && str_contains($message, 'ip_test_tool');
            }));

        $this->auditLogger->log(
            $ctx,
            'ip_test_tool',
            ['data' => 'test'],
            true,
            null,
            25,
            512
        );

        // The IP is added to the entry array but not the log message directly
        // Just verify the method completes without error
        $this->assertTrue(true);
    }

    public function testLogWithAuthFailureAnonymous(): void
    {
        $ctx = new ToolContext(
            principal: null,
            channel: 'public',
            scopes: []
        );

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->callback(function ($message) {
                return str_contains($message, 'AUTH_DENIED')
                    && str_contains($message, 'anonymous');
            }));

        $this->auditLogger->logAuthFailure($ctx, 'protected_tool', 'No credentials provided');
    }

    public function testLogWithSecretField(): void
    {
        $ctx = ToolContext::cli();
        $loggedMessage = null;

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->callback(function ($message) use (&$loggedMessage) {
                $loggedMessage = $message;
                return true;
            }));

        // Test with 'secret' which should be sanitized
        $this->auditLogger->log(
            $ctx,
            'external_service',
            ['secret' => 'my-secret-value', 'endpoint' => '/data'],
            true,
            null,
            30
        );

        $this->assertStringNotContainsString('my-secret-value', $loggedMessage);
        $this->assertStringContainsString('***REDACTED***', $loggedMessage);
    }
}
