<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Tests;

use Milpa\ToolRuntime\Contracts\ResultBudget;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/** Delivery constraints use the transport's encoding and preserve the caller. */
final class ResultBudgetTest extends TestCase
{
    public function testJsonMeasuresTheWholeEnvelopeInCharactersIncludingEscapes(): void
    {
        $value = ['content' => "ñ/\n"];
        $encoded = '{"content":"ñ\\/\\n"}';
        $budget = ResultBudget::json(mb_strlen($encoded, 'UTF-8'));
        self::assertSame($encoded, $budget->encode($value));
        self::assertTrue($budget->fits($value));
        self::assertFalse($budget->tightenedTo($budget->maxCharacters - 1)->fits($value));
        self::assertGreaterThan($budget->maxCharacters, strlen($encoded));
        self::assertSame($budget->maxCharacters, $budget->tightenedTo(9999)->maxCharacters);
    }

    public function testTighteningKeepsTheTransportEncoder(): void
    {
        $budget = new ResultBudget(10, static fn (mixed $v): string => '<' . $v . '>');
        self::assertSame('<abc>', $budget->tightenedTo(5)->encode('abc'));
        self::assertTrue($budget->tightenedTo(5)->fits('abc'));
        self::assertFalse($budget->tightenedTo(4)->fits('abc'));
    }

    public function testInvalidInitialBudgetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ResultBudget::json(0);
    }

    public function testInvalidTighteningIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ResultBudget::json(10)->tightenedTo(-1);
    }

    public function testContextCopyPreservesAuthorityAttributionAndMode(): void
    {
        $original = new ToolContext('reader', 'mcp', ['read'], 'request-1', '127.0.0.1', 'fixture', ['proof' => 'retained'], 'plan');
        $budget = ResultBudget::json(6144);
        $copy = $original->withResultBudget($budget);
        self::assertSame($original->toArray(), $copy->toArray());
        self::assertSame($original->extra, $copy->extra);
        self::assertSame($budget, $copy->resultBudget);
        self::assertSame($budget, $copy->asPlan()->resultBudget);
        self::assertNull($original->resultBudget);
        self::assertNull($copy->withResultBudget(null)->resultBudget);
    }
}
