<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\ToolRuntime\Contracts;

/** A transport-owned bound on the serialized result, never an authorization grant. */
final readonly class ResultBudget
{
    /** @param \Closure(mixed): string $encoder The same encoder the transport uses for this result. */
    public function __construct(public int $maxCharacters, private \Closure $encoder)
    {
        if ($maxCharacters < 1) {
            throw new \InvalidArgumentException('A result budget must be positive.');
        }
    }

    /** The JSON array encoding used by the model tool loop, with Unicode preserved. */
    public static function json(int $maxCharacters): self
    {
        return new self($maxCharacters, static fn (mixed $value): string => json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    /** Encode with the transport's ruler; source bytes and JSON characters are different units. */
    public function encode(mixed $value): string
    {
        return ($this->encoder)($value);
    }

    /** Whether the entire encoded result fits, including its metadata. */
    public function fits(mixed $value): bool
    {
        return mb_strlen($this->encode($value), 'UTF-8') <= $this->maxCharacters;
    }

    /** A producer may request less room; it cannot expand the transport's allowance. */
    public function tightenedTo(int $maxCharacters): self
    {
        if ($maxCharacters < 1) {
            throw new \InvalidArgumentException('A result budget must be positive.');
        }

        return new self(min($this->maxCharacters, $maxCharacters), $this->encoder);
    }
}
