<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A tree with no floor and no baseline (ADR-0017, decisions 6 and 10). Where
 * a CI definition runs the gate, its first run there measures the tree and
 * then fails, as there is no default floor; elsewhere it is advice.
 */
final readonly class TreeFloors
{
    private const string FOUND = 'Neither the config nor the baseline gives a floor to %s.';

    private const string IN_CI
        = 'CI measures such a tree and then fails the run, since there is no default floor; %s runs the gate.';

    private const string LOCAL
        = 'There is no default floor, so once a CI definition runs the gate, such a tree fails the run there.';

    private const string FIX
        = 'Declare a floor for each, as trees: [{path: src, floor: 80}], or commit the baseline a CI run measures.';

    public static function in(Observations $observed): Findings
    {
        $trees = $observed->trees();
        $baseline = $observed->files()->baseline();
        $running = $observed->files()->runningTheGate();

        if (! $trees instanceof Trees || ! $baseline instanceof Baseline) {
            return Findings::none();
        }

        $floorless = self::floorless($trees, $baseline);
        $inCi = $running instanceof Paths && count($running) > 0;

        return $floorless === [] ? Findings::none() : Findings::of(Finding::of(
            Slug::TreeWithoutFloor,
            $inCi ? Severity::WillFail : Severity::Advice,
            sprintf(self::FOUND, implode(', ', $floorless)),
            $inCi ? sprintf(self::IN_CI, self::named($running)) : self::LOCAL,
            self::FIX,
        ));
    }

    /** @return list<string> each tree neither the config nor the baseline gives a floor, and nothing exempts */
    private static function floorless(Trees $trees, Baseline $baseline): array
    {
        $floorless = [];

        foreach ($trees as $tree) {
            $floored = ! $tree->declared() instanceof Undeclared || $baseline->floorOf($tree->path()) instanceof Floor;
            $floorless = $floored ? $floorless : [...$floorless, $tree->path()->value()];
        }

        return $floorless;
    }

    private static function named(Paths $definitions): string
    {
        $named = [];

        foreach ($definitions as $definition) {
            $named[] = $definition->value();
        }

        return implode(', ', $named);
    }
}
