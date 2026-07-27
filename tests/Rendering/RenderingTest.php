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

namespace Milpa\ToolRuntime\Tests\Rendering;

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Rendering\ChannelRendererInterface;
use Milpa\ToolRuntime\Rendering\CliRenderer;
use Milpa\ToolRuntime\Rendering\DefaultRenderer;
use Milpa\ToolRuntime\Rendering\RendererRegistry;
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The layer that turns a {@see ToolResult} into something a person reads.
 *
 * All three classes shipped without ever being executed. This is the last hop
 * before the user: a result rendered wrong is a result the user never sees
 * correctly, however right the tool was.
 */
#[CoversClass(RendererRegistry::class)]
#[CoversClass(DefaultRenderer::class)]
#[CoversClass(CliRenderer::class)]
final class RenderingTest extends TestCase
{
    // ---- the registry -----------------------------------------------------------

    public function testTheRegistryPicksTheRendererThatClaimsTheChannel(): void
    {
        $registry = (new RendererRegistry())
            ->addRenderer($this->rendererFor('telegram', 'desde telegram'))
            ->addRenderer($this->rendererFor('cli', 'desde cli'));

        $rendered = $registry->render(ToolResult::success(), new ToolContext(channel: 'cli'));

        self::assertSame('desde cli', $rendered);
    }

    public function testTheFirstRendererToClaimAChannelWins(): void
    {
        // Two renderers for one channel is a host wiring mistake, not a merge:
        // the registry has to be predictable about which one answers.
        $registry = (new RendererRegistry())
            ->addRenderer($this->rendererFor('cli', 'el primero'))
            ->addRenderer($this->rendererFor('cli', 'el segundo'));

        self::assertSame('el primero', $registry->render(ToolResult::success(), new ToolContext(channel: 'cli')));
    }

    public function testWithNoContextAtAllTheChannelIsDefault(): void
    {
        $registry = (new RendererRegistry())->addRenderer($this->rendererFor('default', 'sin contexto'));

        self::assertSame('sin contexto', $registry->render(ToolResult::success()));
    }

    public function testAnUnclaimedChannelFallsToTheDefaultRenderer(): void
    {
        $registry = (new RendererRegistry())
            ->addRenderer($this->rendererFor('telegram', 'desde telegram'))
            ->setDefaultRenderer($this->rendererFor('cualquiera', 'el de respaldo'));

        self::assertSame('el de respaldo', $registry->render(ToolResult::success(), new ToolContext(channel: 'mcp')));
    }

    public function testWithNothingRegisteredAtAllItFallsBackToJson(): void
    {
        // A registry nobody configured still has to answer something a caller
        // can transmit — losing the result entirely is the one outcome that is
        // never acceptable.
        $rendered = (new RendererRegistry())->render(ToolResult::success(['id' => 7], 'listo'));

        self::assertIsString($rendered);
        self::assertSame(['id' => 7], json_decode($rendered, true)['data']);
    }

    public function testAskingForAChannelsRendererReturnsItOrTheDefault(): void
    {
        $cli = $this->rendererFor('cli', 'x');
        $fallback = $this->rendererFor('nada', 'y');
        $registry = (new RendererRegistry())->addRenderer($cli)->setDefaultRenderer($fallback);

        self::assertSame($cli, $registry->getRenderer('cli'));
        self::assertSame($fallback, $registry->getRenderer('telegram'));
    }

    public function testAskingWhetherAChannelHasARendererAnswersHonestly(): void
    {
        $registry = (new RendererRegistry())->addRenderer($this->rendererFor('cli', 'x'));

        self::assertTrue($registry->hasRenderer('cli'));
        self::assertFalse($registry->hasRenderer('telegram'));
    }

    // ---- the default renderer -------------------------------------------------------

    public function testTheDefaultRendererClaimsEveryChannelBecauseItIsTheLastResort(): void
    {
        $renderer = new DefaultRenderer();

        self::assertTrue($renderer->supports('cli'));
        self::assertTrue($renderer->supports('telegram'));
        self::assertTrue($renderer->supports('un-canal-inventado'));
    }

    public function testASuccessWithNoMessageStillSaysSomething(): void
    {
        self::assertSame(
            'Operation completed successfully.',
            (new DefaultRenderer())->render(ToolResult::success()),
        );
    }

    public function testAListIsRenderedOneItemPerLineWithItsIdAndName(): void
    {
        $result = ToolResult::paginated(
            [['id' => 3, 'nombre' => 'Ana'], ['id' => 4, 'name' => 'Beto'], ['id' => 5, 'title' => 'Caro']],
            page: 1,
            totalItems: 9,
            limit: 3,
            message: 'Clientes',
        );

        $rendered = (new DefaultRenderer())->render($result);

        self::assertStringContainsString('#3: Ana', $rendered);
        self::assertStringContainsString('#4: Beto', $rendered, 'The English key is read too.');
        self::assertStringContainsString('#5: Caro', $rendered);
        self::assertStringContainsString('Page 1 of 3 (9 items total)', $rendered);
    }

    public function testAListItemWithNoNameAtAllIsStillListed(): void
    {
        // Dropping the row would make the count disagree with what is shown.
        $result = ToolResult::paginated([['id' => 1]], page: 1, totalItems: 1, limit: 10);

        self::assertStringContainsString('#1: N/A', (new DefaultRenderer())->render($result));
    }

    public function testADetailIsRenderedAsLabelledLines(): void
    {
        $result = ToolResult::detail(['nombre_completo' => 'Ana', 'tags' => ['a', 'b']], 'Cliente');

        $rendered = (new DefaultRenderer())->render($result);

        self::assertStringContainsString('- Nombre completo: Ana', $rendered, 'The key becomes a readable label.');
        self::assertStringContainsString('- Tags: ["a","b"]', $rendered, 'An array is shown as JSON rather than as "Array".');
    }

    public function testAnErrorIsRenderedAsAnError(): void
    {
        self::assertSame(
            'Error: no se pudo guardar',
            (new DefaultRenderer())->render(ToolResult::error('no se pudo guardar')),
        );
    }

    public function testABlockedResultCarriesItsSuggestion(): void
    {
        // Being told "no" without being told what would work is the difference
        // between a guard and a wall.
        $rendered = (new DefaultRenderer())->render(
            ToolResult::blocked('fuera de horario', 'Intenta después de las 9:00'),
        );

        self::assertStringContainsString('Error: fuera de horario', $rendered);
        self::assertStringContainsString('Suggestion: Intenta después de las 9:00', $rendered);
    }

    public function testAnErrorWithNothingToSayStillNamesItselfAnError(): void
    {
        self::assertStringContainsString(
            'Unknown error',
            (new DefaultRenderer())->render(new ToolResult(false)),
        );
    }

    // ---- the CLI renderer -------------------------------------------------------------

    public function testTheCliRendererClaimsOnlyTheCliChannel(): void
    {
        $renderer = new CliRenderer();

        self::assertTrue($renderer->supports('cli'));
        self::assertFalse($renderer->supports('telegram'));
        self::assertFalse($renderer->supports('default'));
    }

    public function testACliListShowsEachRowItsPageAndItsAvailabilityMarker(): void
    {
        $result = ToolResult::paginated(
            [['id' => 1, 'nombre' => 'Ana', 'available' => true], ['id' => 2, 'name' => 'Beto', 'available' => false]],
            page: 2,
            totalItems: 12,
            limit: 2,
            message: 'Clientes',
        );

        $rendered = (new CliRenderer())->render($result);

        self::assertStringContainsString('<info>Clientes</info>', $rendered);
        self::assertStringContainsString('[OK]', $rendered);
        self::assertStringContainsString('[--]', $rendered);
        self::assertStringContainsString('Ana', $rendered);
        self::assertStringContainsString('Page 2/6 (12 total items)', $rendered);
    }

    public function testAnEmptyCliListSaysSoInsteadOfPrintingAnEmptyFrame(): void
    {
        $result = ToolResult::paginated([], page: 1, totalItems: 0, limit: 10, message: 'Clientes');

        self::assertStringContainsString('No results found.', (new CliRenderer())->render($result));
    }

    public function testACliDetailLabelsItsFieldsAndListsItsActions(): void
    {
        $result = ToolResult::detail(
            ['nombre' => 'Ana', 'activo' => true, 'baja' => false, 'notas' => null, 'tags' => ['a']],
            'Cliente',
            'ficha completa',
            [['label' => 'Editar', 'action' => 'clients.edit']],
        );

        $rendered = (new CliRenderer())->render($result);

        self::assertStringContainsString('<info>Cliente</info> - ficha completa', $rendered);
        self::assertStringContainsString('Ana', $rendered);
        self::assertStringContainsString('<info>Yes</info>', $rendered, 'A boolean reads as a word, not as 1.');
        self::assertStringContainsString('<comment>No</comment>', $rendered, 'And false reads as No, not as an empty string.');
        self::assertStringContainsString('<comment>N/A</comment>', $rendered, 'And null says so instead of vanishing.');
        self::assertStringContainsString('["a"]', $rendered);
        self::assertStringContainsString('Editar: clients.edit', $rendered);
    }

    public function testACliConfirmationSpellsOutWhatIsAboutToHappen(): void
    {
        // The user is being asked to approve something. Showing the verb
        // without the details is asking them to say yes to nothing.
        $result = ToolResult::confirmation('¿Borrar el cliente?', ['cliente' => 'Ana', 'facturas' => 3], 'delete', 'cliente', 7);

        $rendered = (new CliRenderer())->render($result);

        self::assertStringContainsString('CONFIRMATION REQUIRED', $rendered);
        self::assertStringContainsString('¿Borrar el cliente?', $rendered);
        self::assertStringContainsString('Cliente: Ana', $rendered);
        self::assertStringContainsString('Facturas: 3', $rendered);
        self::assertStringContainsString("Type 'yes' to confirm", $rendered);
    }

    public function testACliBlockCarriesTheReasonAndTheWayOut(): void
    {
        $rendered = (new CliRenderer())->render(ToolResult::blocked('fuera de horario', 'Intenta a las 9:00'));

        self::assertStringContainsString('OPERATION BLOCKED', $rendered);
        self::assertStringContainsString('<error>fuera de horario</error>', $rendered);
        self::assertStringContainsString('Intenta a las 9:00', $rendered);
    }

    public function testACliBlockWithNoSuggestionStillRenders(): void
    {
        $rendered = (new CliRenderer())->render(ToolResult::blocked('sin permiso'));

        self::assertStringContainsString('OPERATION BLOCKED', $rendered);
        self::assertStringNotContainsString('Suggestion', $rendered);
    }

    public function testACliErrorIsOneLine(): void
    {
        $rendered = (new CliRenderer())->render(ToolResult::error('no se pudo guardar'));

        self::assertSame("<error>✗ Error: no se pudo guardar</error>\n", $rendered);
    }

    public function testAResultOfNoParticularTypeStillRendersItsMessageAndItsData(): void
    {
        $rendered = (new CliRenderer())->render(ToolResult::success(['filas_afectadas' => 3], 'listo'));

        self::assertStringContainsString('<info>✓</info> listo', $rendered);
        self::assertStringContainsString('Filas afectadas: 3', $rendered);
    }

    public function testAGenericFailureIsMarkedAsOne(): void
    {
        $rendered = (new CliRenderer())->render(new ToolResult(false, null, null, 'algo salió mal'));

        self::assertStringContainsString('<error>✗</error> algo salió mal', $rendered);
    }

    private function rendererFor(string $channel, string $output): ChannelRendererInterface
    {
        return new class ($channel, $output) implements ChannelRendererInterface {
            public function __construct(
                private readonly string $channel,
                private readonly string $output,
            ) {
            }

            public function supports(string $channel): bool
            {
                return $channel === $this->channel;
            }

            public function render(ToolResult $result, ?ToolContext $ctx = null): string
            {
                return $this->output;
            }
        };
    }
}
