<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Heat;

use NightWorksIO\MutationGate\Core\Score\Score;

use function usort;

/**
 * The order the heatmap lists scores in (ADR-0084): lowest first, a set
 * with nothing to mutate last, ties by name.
 *
 * @internal
 */
final readonly class Heats
{
    /**
     * @param  list<Heat> $heats
     * @return list<Heat>
     */
    public static function lowestFirst(array $heats): array
    {
        usort($heats, self::compare(...));

        return $heats;
    }

    /** Which of two scores comes first: the lower, then the name first in order. */
    public static function compare(Heat $one, Heat $other): int
    {
        return [self::rank($one), $one->name()] <=> [self::rank($other), $other->name()];
    }

    /** A score's place: its hundredths, and past every score where there is nothing to mutate. */
    public static function rank(Heat $heat): int
    {
        $score = $heat->score();

        return $score instanceof Score ? $score->hundredths() : PHP_INT_MAX;
    }
}
