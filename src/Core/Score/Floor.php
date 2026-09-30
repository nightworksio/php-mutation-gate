<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

use function floor;
use function intval;

/**
 * The lowest score a set of mutants may have, a percentage. A floor records
 * no more than was written: 83.419 is 83.41.
 */
final readonly class Floor
{
    private function __construct(private Percentage $percentage)
    {
    }

    /** @throws NotAPercentage */
    public static function of(float $percent): self
    {
        $percentage = Percentage::parse($percent);

        return $percentage instanceof Percentage ? new self($percentage) : throw $percentage;
    }

    /** @throws NotAPercentage */
    public static function ofHundredths(int $hundredths): self
    {
        $percentage = Percentage::inHundredths($hundredths);

        return $percentage instanceof Percentage ? new self($percentage) : throw $percentage;
    }

    /** Every mutant killed: the highest floor there is. */
    public static function whole(): self
    {
        return new self(Percentage::whole());
    }

    public function hundredths(): int
    {
        return $this->percentage->hundredths();
    }

    public function percent(): float
    {
        return $this->percentage->percent();
    }

    /** The percent as a config writes it: a whole percent as a whole number, `83.41` as it is. */
    public function written(): int|float
    {
        $percent = $this->percent();

        return floor($percent) === $percent ? intval($percent) : $percent;
    }
}
