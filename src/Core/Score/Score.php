<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

/**
 * The share of counted mutants that were killed, in hundredths of a percent,
 * truncated: 2 of 3 is 66.66, never 66.67.
 */
final readonly class Score
{
    private function __construct(private Percentage $percentage)
    {
    }

    /** @throws NotAPercentage */
    public static function ofHundredths(int $hundredths): self
    {
        return self::known(Percentage::inHundredths($hundredths));
    }

    /**
     * Killed out of counted. With nothing counted there is no score, and
     * that is never the same as every mutant killed.
     *
     * @throws NotAPercentage
     */
    public static function of(int $killed, int $counted): self|NothingToMutate
    {
        if ($counted === 0) {
            return NothingToMutate::found();
        }

        return self::known(Percentage::share($killed, $counted));
    }

    public function hundredths(): int
    {
        return $this->percentage->hundredths();
    }

    public function percent(): float
    {
        return $this->percentage->percent();
    }

    /** @throws NotAPercentage */
    private static function known(Percentage|NotAPercentage $percentage): self
    {
        return $percentage instanceof Percentage ? new self($percentage) : throw $percentage;
    }
}
