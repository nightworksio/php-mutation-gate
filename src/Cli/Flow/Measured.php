<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

/**
 * Each tree's last measured score, from the newest result of every one of its
 * units in the ledgers the run reads: its own scope's and the default
 * branch's. A tree with a unit that has no result yet has no measured score.
 */
final readonly class Measured
{
    private function __construct(private TreeVerdicts $trees, private Paths $unmeasured)
    {
    }

    public static function of(
        Adapters $adapters,
        Settings $settings,
        Baseline $baseline,
        DateTimeImmutable $now,
    ): self|CannotJudge {
        $inventory = Inventory::of($adapters, $settings);

        if ($inventory instanceof CannotJudge) {
            return $inventory;
        }

        $ledgers = Ledgers::read($adapters->proofs, $inventory->standing, Writing::Never);
        $newest = Considering::of(
            $inventory->units,
            Reach::nothing(Packages::of($inventory->trees)),
            $ledgers->defaultBranch()->proofs(),
            $ledgers->own()->proofs(),
        );
        $unmeasured = Paths::none();

        foreach ($newest->considered() as $unit) {
            $tree = $inventory->trees->holding($unit->path());
            $unmeasured = $tree instanceof Tree ? $unmeasured->with($tree->path()) : $unmeasured;
        }

        $judge = Judge::of(
            self::measuredOf($inventory->trees, $unmeasured),
            $baseline,
            Reach::nothing(Packages::of($inventory->trees)),
            Uncovered::from($settings->floors()->uncovered()->value),
            $settings->triage()->timeouts(),
            Ignoring::of($settings->ignores()->entries(), $now),
        );

        return new self($judge->trees($newest->carried()), $unmeasured);
    }

    /** Every tree whose every unit has a result, judged over those results. */
    public function trees(): TreeVerdicts
    {
        return $this->trees;
    }

    /** The trees with a unit that has no result yet, and so no measured score. */
    public function unmeasured(): Paths
    {
        return $this->unmeasured;
    }

    private static function measuredOf(Trees $trees, Paths $unmeasured): Trees
    {
        $measured = Trees::none();

        foreach ($trees as $tree) {
            $measured = $unmeasured->has($tree->path()) ? $measured : $measured->with($tree);
        }

        return $measured;
    }
}
