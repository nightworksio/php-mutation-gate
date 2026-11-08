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
    public const int STANDARD = 20;

    private function __construct(private int $farthest)
    {
    }

    /** Twenty commits. */
    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** This many commits. */
    public static function of(int $farthest): self
    {
        return new self($farthest);
    }

    /** The most commits it asks about. */
    public function farthest(): int
    {
        return $this->farthest;
    }
}
