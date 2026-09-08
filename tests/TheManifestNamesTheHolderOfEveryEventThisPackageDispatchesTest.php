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

use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\ToolRuntime\Events\ToolRuntimeEvents;
use PHPUnit\Framework\TestCase;

/**
 * Falsifier for greenhouse decisions/0228, second slice: the manifest — not the prose — names the
 * class that speaks for this package's events.
 *
 * An emitter declares when it is CONSTRUCTED, so a process that never builds one never hears its
 * events (measured on cattle: a CLI run knew seven of the family's twenty-four). The fix is the
 * mechanism a capability already uses for its provider: the package NAMES its holder in
 * `composer.json`, and a host reads it from `vendor/composer/installed.json`. This test reads the
 * package's own `composer.json` from disk and measures that entry: the class it names must exist,
 * must be a {@see DeclaresEvents} holder, and must answer the same event names the holder answers.
 * A typo, a rename that forgot the manifest, or a manifest pointing at the wrong class goes red
 * here instead of turning into a host-side warning nobody reads.
 */
final class TheManifestNamesTheHolderOfEveryEventThisPackageDispatchesTest extends TestCase
{
    /**
     * The holders this package's manifest must name, hardcoded so the test never asks the manifest
     * to confirm itself.
     *
     * @var list<class-string>
     */
    private const EXPECTED_HOLDERS = [ToolRuntimeEvents::class];

    /**
     * The exact event names the manifest's holder must answer, hardcoded for the same reason.
     */
    private const EXPECTED_NAMES = [
        'tool.executing',
        'tool.executed',
        'tool.failed',
        'verification.requested',
        'verification.granted',
        'verification.rejected',
    ];

    /**
     * `extra.milpa.events` is a list naming exactly this package's holders — the sibling
     * `extra.milpa.capability` untouched beside it, since both keys are read from the same
     * installed manifest.
     */
    public function testTheManifestListsExactlyTheHoldersOfThisPackage(): void
    {
        $extra = $this->manifest()['extra']['milpa'] ?? null;

        $this->assertIsArray($extra, 'composer.json must keep an extra.milpa section');
        $this->assertArrayHasKey('events', $extra, 'extra.milpa.events must name the event holders');
        $this->assertSame(self::EXPECTED_HOLDERS, $extra['events']);
        $this->assertArrayHasKey('capability', $extra, 'adding events must not displace the capability declaration');
    }

    /**
     * Every class the manifest names is loadable and is a holder: a host that resolves the string
     * gets a class it can call `::declarations()` on, never a fatal error or a wrong contract.
     */
    public function testEveryClassTheManifestNamesExistsAndIsAHolderOfDeclarations(): void
    {
        $named = $this->manifest()['extra']['milpa']['events'];
        $this->assertNotEmpty($named, 'a manifest that names nothing declares nothing');

        foreach ($named as $class) {
            $this->assertIsString($class);
            $this->assertTrue(class_exists($class), $class . ' is named in composer.json but does not exist');
            $this->assertTrue(is_a($class, DeclaresEvents::class, true), $class . ' is named in composer.json but is not a DeclaresEvents holder');
        }
    }

    /**
     * The class named IN THE MANIFEST answers exactly the event names this package dispatches —
     * the same set {@see ToolRuntimeEvents::declarations()} answers. This is the check a broken
     * manifest string fails: a renamed or mistyped entry stops resolving to the holder that
     * carries these six names.
     */
    public function testTheHolderNamedInTheManifestAnswersTheSameEventsThePackageDispatches(): void
    {
        $fromManifest = [];
        foreach ($this->manifest()['extra']['milpa']['events'] as $class) {
            /** @var list<EventDeclaration> $declarations */
            $declarations = $class::declarations();
            foreach ($declarations as $declaration) {
                $fromManifest[] = $declaration->name;
            }
        }

        $this->assertSame(self::EXPECTED_NAMES, $fromManifest);
        $this->assertSame($this->names(ToolRuntimeEvents::declarations()), $fromManifest);
    }

    /**
     * This package's own `composer.json`, decoded from disk — the file Composer publishes and a
     * host later reads back out of `vendor/composer/installed.json`.
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = dirname(__DIR__) . '/composer.json';
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<EventDeclaration> $declarations
     *
     * @return list<string>
     */
    private function names(array $declarations): array
    {
        return array_map(static fn (EventDeclaration $d): string => $d->name, $declarations);
    }
}
