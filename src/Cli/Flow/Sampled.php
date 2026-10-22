<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Draw;
use NightWorksIO\MutationGate\Core\Sample\Drawing;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Sample\SampleSize;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\HeldFloor;

/**
 * What a sampled run's plan draws of the units no proof covers (ADR-0020).
 * A unit is in its tree's sampled stratum unless it is known exactly: its
 * tree is held to a floor of 100, it holds a line of new code, a
 * change-scoped run reaches it, or nothing estimates the mutants it holds,
 * which is a held unit no proof records. Each unit is estimated to hold as
 * many mutants as its newest proof counts, or else as many as the gate's
 * own engine makes of its file. The seed is `sampling.seed`, or the
 * change's base commit in a change-scoped run and the plan's commit
 * otherwise.
 */
final readonly class Sampled
{
    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /**
     * The sample a run of this mode draws of these units to run, of a run
     * that reads its change since this base; none for a run that is not
     * sampled, or whose new code cannot be told; or why it cannot be drawn.
     */
    public function drawn(
        Mode $mode,
        Inventory $inventory,
        Units $toRun,
        Reached $reached,
        Changes|CannotJudge $newCode,
        Ledgers $ledgers,
        ChangeBase|CannotTell $base,
    ): Sample|CannotJudge {
        if (! $mode->isSampled() || ! $newCode instanceof Changes) {
            return Sample::none();
        }

        $baseline = new Baselines($this->adapters, $this->settings->floors()->baseline())->committed();

        return $baseline instanceof CannotJudge
            ? $baseline
            : $this->sampleOf($inventory, $toRun, $reached, $newCode, $ledgers, $base, $baseline);
    }

    private function sampleOf(
        Inventory $inventory,
        Units $toRun,
        Reached $reached,
        Changes $newCode,
        Ledgers $ledgers,
        ChangeBase|CannotTell $base,
        Baseline $baseline,
    ): Sample {
        $sampling = $this->settings->reach()->sampling();
        $seed = SampleSeed::of($this->adapters, $sampling, $inventory->standing->head(), $base);
        $drawing = Drawing::of(
            Draw::seeded($seed),
            SampleSize::of($sampling->margin(), $sampling->confidence(), $sampling->minUnits()),
        );
        $sample = Sample::drawn($seed, $sampling->confidence(), $sampling->margin());
        $exact = $this->exact($inventory, $reached, $newCode);
        $estimated = UnitMutants::of($this->adapters, $this->settings, $ledgers->newest());

        foreach ($inventory->trees as $tree) {
            $units = $inventory->units->within($this->heldBy($inventory, $tree));
            $sample = $this->isWhole($tree, $baseline)
                ? $sample
                : $this->tree($sample, $drawing, $units, $toRun->except($exact), $estimated);
        }

        return $sample;
    }

    /**
     * The sample, holding the draw of one tree's units: of those it runs that
     * no run knows exactly, of the units whose mutants can be estimated.
     */
    private function tree(
        Sample $sample,
        Drawing $drawing,
        Units $units,
        Units $unknown,
        UnitMutants $estimated,
    ): Sample {
        $candidates = [];

        foreach ($units as $unit) {
            $candidate = $estimated->candidate($unit);
            $candidates = $candidate instanceof Candidate ? [...$candidates, $candidate] : $candidates;
        }

        return $drawing->into($sample, $unknown, ...$candidates);
    }

    /** The paths of the units a tree holds. */
    private function heldBy(Inventory $inventory, Tree $tree): Paths
    {
        $paths = [];

        foreach ($inventory->units as $unit) {
            $paths = $inventory->trees->holding($unit->path()) === $tree ? [...$paths, $unit->path()] : $paths;
        }

        return Paths::of(...$paths);
    }

    /**
     * The units known exactly though they run: those of new code, and those
     * a change-scoped run reaches.
     */
    private function exact(Inventory $inventory, Reached $reached, Changes $newCode): Units
    {
        $lines = Reach::nothing(Packages::of($inventory->trees));

        foreach ($newCode as $change) {
            $lines = $lines->withLines($change->path(), $change->lines());
        }

        $scoped = $reached->changed() instanceof Changes;
        $exact = Units::none();

        foreach ($inventory->units as $unit) {
            $known = $lines->changesLinesOf($unit) || ($scoped && $reached->reach()->reaches($unit));
            $exact = $known ? $exact->with($unit) : $exact;
        }

        return $exact;
    }

    /** Whether a tree is held to a floor of 100, which no sample can show holds, so it runs whole. */
    private function isWhole(Tree $tree, Baseline $baseline): bool
    {
        $floor = HeldFloor::of($tree->declared(), $baseline->floorOf($tree->path()))->floor();

        return $floor instanceof Floor && $floor->hundredths() === Floor::whole()->hundredths();
    }
}
