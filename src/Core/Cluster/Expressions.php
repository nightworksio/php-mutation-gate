<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

use function array_filter;
use function array_values;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\Report\Columns;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function usort;

/**
 * Survivors of one file whose changes overlap within one statement, such as
 * `<` to `<=`, `<` to `>` and the negated condition of one `if`: one boundary
 * test kills them all. Overlap is read through: where a overlaps b and b
 * overlaps c, all three are one group.
 */
final readonly class Expressions
{
    /**
     * @param  list<JudgedMutant>       $mutants
     * @return list<list<JudgedMutant>> each group of two or more
     */
    public static function among(array $mutants, Columns $columns): array
    {
        $placed = [];

        foreach ($mutants as $judged) {
            $span = $columns->span($judged->mutant());

            if ($span instanceof Span) {
                $placed[] = [$judged, $span];
            }
        }

        usort(
            $placed,
            static fn(array $one, array $other): int => [$one[1]->first(), $one[1]->last()]
                <=> [$other[1]->first(), $other[1]->last()],
        );

        return self::overlapping($placed);
    }

    /**
     * Placed mutants, in order of where they begin, cut into runs that overlap.
     *
     * @param  list<array{JudgedMutant, Span}> $placed
     * @return list<list<JudgedMutant>>
     */
    private static function overlapping(array $placed): array
    {
        $groups = [];
        $reach = -1;

        foreach ($placed as [$judged, $span]) {
            if ($span->first() > $reach) {
                $groups[] = [];
            }

            $groups[count($groups) - 1][] = $judged;
            $reach = max($reach, $span->last());
        }

        return array_values(array_filter($groups, static fn(array $members): bool => count($members) > 1));
    }
}
