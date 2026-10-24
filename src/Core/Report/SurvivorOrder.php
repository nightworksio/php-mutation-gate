<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_diff_key;
use function array_filter;
use function array_keys;
use function array_map;
use function asort;

/**
 * The order a report's survivors are listed in, as the HTML report and the
 * digest list them: those on changed lines first, then by file and line.
 */
final readonly class SurvivorOrder
{
    /**
     * @template T
     *
     * @param  list<T>                         $survivors
     * @param  callable(T): bool               $changed   whether a survivor is on a changed line
     * @param  callable(T): array{string, int} $where     a survivor's file and line
     * @return list<T>
     */
    public static function of(array $survivors, callable $changed, callable $where): array
    {
        $places = array_map($where, $survivors);
        asort($places);
        $sorted = [];

        foreach (array_keys($places) as $at) {
            $sorted[] = $survivors[$at];
        }

        $first = array_filter($sorted, $changed);

        return [...$first, ...array_diff_key($sorted, $first)];
    }
}
