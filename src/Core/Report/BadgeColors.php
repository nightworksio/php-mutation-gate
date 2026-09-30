<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function arsort;

use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;

/**
 * `badge.colors`: each shields.io colour with the lowest score that earns it,
 * and red below them all (ADR-0009, decision 5).
 */
final readonly class BadgeColors
{
    private const array DEFAULTS = ['brightgreen' => 90, 'green' => 80, 'yellow' => 70, 'orange' => 60];

    private const string BELOW = 'red';

    /** The colour of a badge with no score to show. */
    private const string NONE = 'lightgrey';

    /** @param array<string, int> $bands each colour's lowest score in hundredths, highest first */
    private function __construct(private array $bands)
    {
    }

    public static function defaults(): self
    {
        return self::of(self::DEFAULTS);
    }

    /** @param array<string, int|float> $lowest each colour's lowest score, as a percentage */
    public static function of(array $lowest): self
    {
        $bands = [];

        foreach ($lowest as $color => $percent) {
            $bands[$color] = Percentage::hundredthsOf($percent);
        }

        arsort($bands);

        return new self($bands);
    }

    public function colorOf(Score|NothingToMutate $score): string
    {
        if ($score instanceof NothingToMutate) {
            return self::NONE;
        }

        foreach ($this->bands as $color => $lowest) {
            if ($score->hundredths() >= $lowest) {
                return $color;
            }
        }

        return self::BELOW;
    }
}
