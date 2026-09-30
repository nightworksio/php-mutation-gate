<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

/**
 * How many commits since a base a change source asks to have proved, one
 * question each, before it reads the range whole instead. Past that, a full
 * reach is the safe answer.
 */
final readonly class ProvingRange
{
    private const int STANDARD = 20;

    private function __construct(private int $farthest)
    {
    }

    /** Twenty commits. */
    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** The most commits it asks about. */
    public function farthest(): int
    {
        return $this->farthest;
    }
}
