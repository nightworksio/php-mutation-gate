<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Owner\OwnerGroup;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Core\Report\Folded;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Owned;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * Every mutant the score counts as not killed, as the console lists it: each
 * with its diff, hint, judging tests, reproduce and explain commands, and
 * each cluster once with its members' diffs and stub command; under the
 * owner group of their files, each headed by its owners and how many
 * survivors it has, where the run read a CODEOWNERS (ADR-0022).
 */
final readonly class SurvivorLines
{
    /** @return list<string> */
    public static function of(Verdict $verdict, Overview $overview): array
    {
        $items = Folded::of($overview->survivors(), $verdict->trees()->clusters());
        $lines = [];

        foreach (Owned::parts($overview->survivors(), $items, count($items), $verdict->sets()->owners()) as $part) {
            $group = $part->group();
            $blocks = self::blocks($verdict, $part->shown());
            $lines = $group instanceof OwnerGroup
                ? [
                    ...$lines,
                    ...$lines === [] ? [] : [''],
                    sprintf('%s (%d)', Fit::plain($group->name()), $part->survivors()),
                    ...Indented::lines($blocks),
                ]
                : $blocks;
        }

        return $lines;
    }

    /**
     * Each mutant's block and each cluster's, as one text; nothing where there are none.
     *
     * @param  list<JudgedMutant|Cluster> $items
     * @return list<string>
     */
    private static function blocks(Verdict $verdict, array $items): array
    {
        $blocks = [];

        foreach ($items as $item) {
            $blocks[] = $item instanceof Cluster
                ? ClusterText::block($item)
                : MutantText::block($item, $verdict->matrix()->names());
        }

        return $blocks === [] ? [] : [implode("\n\n", $blocks)];
    }
}
