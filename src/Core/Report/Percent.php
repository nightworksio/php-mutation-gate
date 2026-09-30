<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function intdiv;

use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

use function sprintf;

/**
 * A score or a floor as every report prints it: two decimals, never rounded,
 * so 83.419 is 83.41%. A set with nothing to mutate has no percentage, and
 * never prints as 100%.
 */
final readonly class Percent
{
    /** What a set with no score prints instead of a percentage. */
    public const string NOTHING = 'nothing to mutate';
    private const int HUNDREDTHS = 100;

    public static function of(Score|Floor|NothingToMutate $value): string
    {
        return $value instanceof NothingToMutate
            ? self::NOTHING
            : sprintf(
                '%d.%02d%%',
                intdiv($value->hundredths(), self::HUNDREDTHS),
                $value->hundredths() % self::HUNDREDTHS,
            );
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

        return sprintf('%s%d.%02d', $sign, intdiv($size, self::HUNDREDTHS), $size % self::HUNDREDTHS);
    }
}
