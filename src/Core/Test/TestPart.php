<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function max;
use function min;

/**
 * The part of the test files whose tests judge a shard's mutants, of how
 * many parts a run cuts them into (ADR-0117): part 1 of 1, every test file,
 * where a run does not cut them. Parts are counted from 1.
 */
final readonly class TestPart
{
    private function __construct(private int $number, private int $parts)
    {
    }

    /** Every test file, as one part. */
    public static function whole(): self
    {
        return new self(1, 1);
    }

    /** Part `number` of `parts`, each held to a part there is. */
    public static function of(int $number, int $parts): self
    {
        $parts = max(1, $parts);

        return new self(min(max(1, $number), $parts), $parts);
    }

    /** Its number, counted from 1. */
    public function number(): int
    {
        return $this->number;
    }

    /** How many parts the test files are cut into. */
    public function parts(): int
    {
        return $this->parts;
    }

    /** Whether it is every test file, the test files cut into no parts. */
    public function isWhole(): bool
    {
        return $this->parts === 1;
    }
}
