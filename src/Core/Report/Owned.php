<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\OwnerVerdicts;

/**
 * Survivors as a report lists them by owner: each under the owner group of
 * its file, a cluster under its representative's, the groups in the
 * verdict's order, each one's survivors in theirs, and a group with none
 * left out (ADR-0022).
 */
final readonly class Owned
{
    /**
     * Up to this many items, in their parts: one part a group, or one of them
     * all where the run read no CODEOWNERS.
     *
     * @param  iterable<JudgedMutant>     $survivors
     * @param  list<JudgedMutant|Cluster> $items     the survivors, folded
     * @return list<OwnedPart>
     */
    public static function parts(iterable $survivors, array $items, int $shown, OwnerVerdicts $owners): array
    {
        if (! $owners->areGrouped()) {
            return [new OwnedPart(
                NotGiven::value(),
                count([...$survivors]),
                array_slice($items, 0, $shown),
                count($items),
            )];
        }

        $counts = [];
        $grouped = [];

        foreach ($owners as $verdict) {
            $counts[$verdict->group()->name()] = 0;
            $grouped[$verdict->group()->name()] = [];
        }

        foreach ($survivors as $survivor) {
            ++$counts[self::nameOf($survivor, $owners)];
        }

        foreach ($items as $item) {
            $grouped[self::nameOf($item, $owners)][] = $item;
        }

        $parts = [];

        foreach ($owners as $verdict) {
            $of = $grouped[$verdict->group()->name()];
            $here = array_slice($of, 0, $shown);
            $shown -= count($here);
            $parts = $of === []
                ? $parts
                : [...$parts, new OwnedPart($verdict->group(), $counts[$verdict->group()->name()], $here, count($of))];
        }

        return $parts;
    }

    private static function nameOf(JudgedMutant|Cluster $item, OwnerVerdicts $owners): string
    {
        $judged = $item instanceof Cluster ? $item->representative() : $item;

        return $owners->groupOf($judged->mutant()->location()->file())->name();
    }
}
