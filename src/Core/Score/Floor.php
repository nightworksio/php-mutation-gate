<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

use function intval;

/**
 * The lowest score a set of mutants may have, a percentage in hundredths. A
 * floor is never lower than was written: 83.411 is 83.42.
 */
final readonly class Floor
{
    private function __construct(private Percentage $percentage)
    {
    }

    /** @throws NotAPercentage */
    public static function of(float $percent): self
    {
        $percentage = Percentage::parseUp($percent);

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
        $whole = intval($percent);

        return (float) $whole === $percent ? $whole : $percent;
    }
}
