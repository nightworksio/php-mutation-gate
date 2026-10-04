<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Plan\Considering;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Report\ScoreChangeText;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\BaseScores;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

/**
 * The score change the working tree makes against the merge base with the
 * default branch, from what the ledgers hold, running nothing (ADR-0015,
 * decisions 10 to 12). A reached unit is judged by the local result keyed to
 * what is on disk, and otherwise carries the newest result the ledgers hold
 * for it, and is counted as unjudged. The keys come from the coverage map the
 * last local run left, so where there is none, no score is shown.
 */
final readonly class ScoreChanging
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    public function text(): string|CannotJudge
    {
        $inventory = Inventory::of($this->adapters, $this->settings);
        $baseline = new Baselines($this->adapters, $this->settings->floors()->baseline())->committed();
        $map = $this->adapters->runner->coverage(CoverageRead::from(Workspace::coverage()));

        return match (true) {
            $inventory instanceof CannotJudge => $inventory,
            $baseline instanceof CannotJudge => $baseline,
            $map instanceof CannotJudge => ScoreChangeText::unmapped(),
            default => $this->keyed($inventory, $baseline, $map),
        };
    }

    private function keyed(Inventory $inventory, Baseline $baseline, CoverageMap $map): string|CannotJudge
    {
        $keying = Keying::of($this->adapters, $this->settings, $this->setup, $inventory->suite, $map);

        return $keying instanceof Keying ? $this->changed($inventory, $baseline, $map, $keying) : $keying;
    }

    private function changed(Inventory $inventory, Baseline $baseline, CoverageMap $map, Keying $keying): string
    {
        $trees = $inventory->trees;
        $ledgers = Ledgers::read($this->adapters->proofs, $inventory->standing, Writing::Never);
        $defaultBranch = $ledgers->defaultBranch()->proofs();
        $reach = Reached::since(
            $inventory->standing->fetchedDefaultBranch(),
            $trees,
            $this->adapters,
            $this->settings,
            $inventory->suite,
            $map,
        )->reach();
        $considering = Considering::of($inventory->units, $reach, $defaultBranch, $ledgers->own()->proofs());
        $reached = $considering->considered();
        $proving = $ledgers->proving($reached, $keying->keysOf($reached), $keying->base(), MatrixKind::FirstKiller);
        $completing = Considering::of(
            $proving->toRun(),
            Reach::nothing(Packages::of($trees)),
            $defaultBranch,
            $ledgers->own()->proofs(),
        );
        $judge = Judge::of(
            $trees,
            $baseline,
            $reach,
            Uncovered::from($this->settings->floors()->uncovered()->value),
            $this->settings->triage()->timeouts(),
            Ignoring::of($this->settings->ignores()->entries(), $this->setup->clock->now()),
        );
        $judged = $proving->proved()->and($considering->carried())->and($completing->carried());
        $proven = $judge->proving(new StaticEquivalence($this->adapters, $this->settings)->among($judged)->proven);
        $verdicts = BaseScores::of($proven, $trees, $inventory->units, $defaultBranch)
            ->compare($proven->trees($judged));
        $unmeasured = $this->treesOf($completing->considered(), $trees);

        return ScoreChangeText::of(
            $this->measured($verdicts, $this->treesOf($reached, $trees), $unmeasured),
            $unmeasured,
            $proving->toRun()->count(),
            $this->unstagedIn($reached),
        );
    }

    /** The trees these units are in. */
    private function treesOf(Units $units, Trees $trees): Paths
    {
        $holding = Paths::none();

        foreach ($units as $unit) {
            $tree = $trees->holding($unit->path());
            $holding = $tree instanceof Tree ? $holding->with($tree->path()) : $holding;
        }

        return $holding;
    }

    /** The verdicts of the reached trees whose every unit has a result. */
    private function measured(TreeVerdicts $verdicts, Paths $reached, Paths $unmeasured): TreeVerdicts
    {
        $measured = TreeVerdicts::none();

        foreach ($verdicts as $verdict) {
            $path = $verdict->tree()->path();
            $measured = $reached->has($path) && ! $unmeasured->has($path) ? $measured->with($verdict) : $measured;
        }

        return $measured;
    }

    /** How many files of these units hold changes that are not staged; none where git cannot tell. */
    private function unstagedIn(Units $units): int
    {
        $unstaged = $this->adapters->changes->unstaged();
        $counted = 0;

        foreach ($unstaged instanceof Paths ? $unstaged : Paths::none() as $file) {
            foreach ($units as $unit) {
                if ($file->within($unit->path())) {
                    ++$counted;

                    break;
                }
            }
        }

        return $counted;
    }
}
