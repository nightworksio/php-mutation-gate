<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;

use function sprintf;

/**
 * A score or a floor as every report prints it: two decimals, never rounded,
 * so 83.419 is 83.41%. A set with nothing to mutate has no percentage, and
 * never prints as 100%.
 */
final readonly class Percent
{
    public static function of(Score|Floor|NothingToMutate $value): string
    {
        return $value instanceof NothingToMutate
            ? NothingToMutate::SAID
            : sprintf('%s%%', Percentage::points($value->hundredths()));
    }

    /** The change from one score to another, signed, in points: `+1.20`, `-0.05`, `±0.00`. */
    public static function change(Score $from, Score $to): string
    {
        $difference = $to->hundredths() - $from->hundredths();
        $size = $difference < 0 ? -$difference : $difference;
        $sign = match (true) {
            $difference > 0 => '+',
            $difference < 0 => '-',
            default => '±',
        };

        return sprintf('%s%s', $sign, Percentage::points($size));
    }
}
