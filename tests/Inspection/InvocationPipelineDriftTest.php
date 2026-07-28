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

namespace Milpa\ToolRuntime\Tests\Inspection;

use Milpa\Eventing\EventDispatcher;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Inspection\InvocationStepKind;
use Milpa\ToolRuntime\RateLimiting\RateLimiterInterface;
use Milpa\ToolRuntime\RateLimiting\RateLimitResult;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE DRIFT-GUARD (ADR#13 P13.1): ata {@see InvocationStepKind::inspectionOrder()} — el orden del
 * MODELO inspeccionable (Task 1) — al orden que {@see ToolRegistry::call()} REALMENTE ejecuta.
 *
 * El suite existente ({@see \Milpa\ToolRuntime\Tests\ToolRegistryTest}) pinnea *outcomes* por
 * gate, pero el único orden inter-gate ya pinneado antes de este test era authorize-antes-del-
 * anchor ({@see \Milpa\ToolRuntime\Tests\Events\CacheShortCircuitTest::testUnauthorizedCallNeverReachesTheCacheListener}).
 * Un reorder de validate/clamp/rate-limit/plan-mode/confirm dentro de `call()` pasaría verde sin
 * este test. Aquí se manejan tools sintéticos a través del `call()` REAL y un
 * {@see EventDispatcher} REAL — sin mocking de la mecánica de dispatch — precisamente porque lo
 * que se prueba es la precedencia REAL de los gates, no que la tubería compile.
 *
 * Cero código de producción se toca aquí: `call()` queda byte-idéntico. Este test refleja la ley
 * ejecutable, no al revés — si algún caso de precedencia difiriera de lo documentado en la
 * especificación, el comportamiento observado de `call()` gana.
 */
class InvocationPipelineDriftTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private ToolRegistry $registry;

    /** @var list<string> Secuencia observada: 'executing' | 'callback' | 'executed'. */
    private array $seq = [];

    protected function setUp(): void
    {
        parent::setUp();

        $logger = new NullLogger();
        $this->dispatcher = new EventDispatcher($logger);
        $this->registry = new ToolRegistry($logger, $this->dispatcher);
        $this->seq = [];

        $this->dispatcher->subscribe('tool.executing', function (string $eventName, array $payload): void {
            $this->seq[] = 'executing';
        });

        $this->dispatcher->subscribe('tool.executed', function (string $eventName, array $payload): void {
            $this->seq[] = 'executed';
        });
    }

    /**
     * Registra un tool sintético cuyo callback anota 'callback' en $this->seq y devuelve un
     * ToolResult exitoso vacío — suficiente para observar CUÁNDO corre (o no corre) el
     * callback real sin acoplar el test a ningún dato de negocio.
     *
     * @param array<string, mixed> $inputSchema
     */
    private function register(string $name, ToolOptions $opts, array $inputSchema = []): void
    {
        $this->registry->register(
            $name,
            "Synthetic tool {$name} (drift-guard fixture)",
            $inputSchema,
            function (array $args): ToolResult {
                $this->seq[] = 'callback';

                return ToolResult::success([]);
            },
            $opts
        );
    }

    /**
     * Limiter que SIEMPRE deniega — la implementación más simple de
     * {@see RateLimiterInterface} que devuelve el shape "denegado" REAL
     * ({@see RateLimitResult::denied()}), verificado leyendo la interfaz y el VO en vez de
     * inventar la forma de retorno.
     */
    private function denyingRateLimiter(): RateLimiterInterface
    {
        return new class () implements RateLimiterInterface {
            public function consume(string $key, int $cost = 1, int $windowSeconds = 60, int $maxTokens = 100): RateLimitResult
            {
                return RateLimitResult::denied('drift-guard: limiter siempre deniega', 60);
            }

            public function getUsage(string $key): int
            {
                return 0;
            }

            public function reset(string $key): void
            {
            }
        };
    }

    public function test_happy_path_emits_executing_then_callback_then_executed(): void
    {
        // Tool sin scopes, ctx cli (autorizado) → todos los gates pasan.
        $this->register('happy', new ToolOptions());
        $this->seq = [];

        $result = $this->registry->call('happy', [], ToolContext::cli());

        self::assertTrue($result->success);
        self::assertSame(
            ['executing', 'callback', 'executed'],
            $this->seq,
            'el anchor dispara una vez, ANTES del callback; executed después'
        );
    }

    public function test_resolve_precedes_validate(): void
    {
        // Tool inexistente → TOOL_NOT_FOUND aunque los args fallarían validación.
        $result = $this->registry->call('no-existe', ['x' => 'bad'], ToolContext::cli());

        self::assertSame(ToolResult::TOOL_NOT_FOUND, $result->meta['code'] ?? null);
    }

    public function test_validate_precedes_authorize(): void
    {
        // Tool con inputSchema que exige 'name' + con scopes que el ctx web-anónimo no tiene →
        // gana VALIDATION_ERROR (validate corre antes que authorize).
        $this->register(
            'needs_name',
            new ToolOptions(scopes: ['milpa.admin']),
            ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]
        );

        $result = $this->registry->call('needs_name', [], ToolContext::web('u', []));

        self::assertSame(ToolResult::VALIDATION_ERROR, $result->meta['code'] ?? null);
    }

    public function test_authorize_precedes_rate_limit(): void
    {
        // Limiter que deniega + tool con scope que falta → gana FORBIDDEN (authorize antes que rate).
        $this->registry->setRateLimiter($this->denyingRateLimiter());
        $this->register('scoped', new ToolOptions(scopes: ['milpa.admin']));

        $result = $this->registry->call('scoped', [], ToolContext::web('u', []));

        self::assertSame(ToolResult::FORBIDDEN, $result->meta['code'] ?? null);
    }

    public function test_rate_limit_precedes_confirm(): void
    {
        // Limiter que deniega + tool confirm:true + ctx autorizado sin token → gana RATE_LIMITED
        // (rate antes que confirm). Por telegram: en `cli` un confirmable sin firma se deniega en
        // authorize, que va antes que el rate limiter, y la precedencia observada sería otra.
        $this->registry->setRateLimiter($this->denyingRateLimiter());
        $this->register('confirmable', new ToolOptions(requiresConfirmation: true));

        $result = $this->registry->call('confirmable', [], ToolContext::telegram('chat-1'));

        self::assertSame(ToolResult::RATE_LIMITED, $result->meta['code'] ?? null);
    }

    public function test_plan_mode_precedes_the_anchor(): void
    {
        $this->register('planned', new ToolOptions(requiresConfirmation: true));
        $this->seq = [];

        // Igual que arriba: el confirmable necesita un canal donde el consentimiento siga siendo
        // un token, o authorize lo detiene antes de que plan-mode pueda demostrar su precedencia.
        $result = $this->registry->call('planned', [], ToolContext::telegram('chat-1', mode: 'plan'));

        self::assertTrue($result->success);
        self::assertNotContains('executing', $this->seq, 'plan-mode retorna ANTES del anchor');
        self::assertNotContains('callback', $this->seq);
    }

    public function test_confirm_precedes_the_anchor(): void
    {
        $this->register('confirmable2', new ToolOptions(requiresConfirmation: true));
        $this->seq = [];

        // Por telegram, no por cli: en `cli` el consentimiento ahora es una firma, así que un
        // confirmable sin firmar se deniega en authorize y jamás alcanza el paso de confirmación.
        // El canal que todavía confirma con token es el que sirve para observar ese paso.
        $result = $this->registry->call('confirmable2', [], ToolContext::telegram('chat-1')); // sin token

        self::assertNotContains('executing', $this->seq, 'confirm retorna ANTES del anchor');
        self::assertSame('confirmation', $result->meta['type'] ?? null);
    }

    public function test_inspection_order_is_consistent_with_the_observed_gate_precedence(): void
    {
        // El binding: cada par (a antes de b) observado arriba debe respetar inspectionOrder().
        $order = InvocationStepKind::inspectionOrder();
        $index = static fn (InvocationStepKind $k): int|false => array_search($k, $order, true);

        $observedPairs = [
            [InvocationStepKind::Resolve, InvocationStepKind::Validate],
            [InvocationStepKind::Validate, InvocationStepKind::Authorize],
            [InvocationStepKind::Authorize, InvocationStepKind::RateLimit],
            [InvocationStepKind::RateLimit, InvocationStepKind::Confirm],
            [InvocationStepKind::PlanMode, InvocationStepKind::EmitExecuting],
            [InvocationStepKind::Confirm, InvocationStepKind::EmitExecuting],
            [InvocationStepKind::EmitExecuting, InvocationStepKind::Execute],
            [InvocationStepKind::Execute, InvocationStepKind::Audit],
        ];

        foreach ($observedPairs as [$before, $after]) {
            self::assertLessThan(
                $index($after),
                $index($before),
                "inspectionOrder() debe listar {$before->value} antes de {$after->value} (orden observado en call())"
            );
        }
    }
}
