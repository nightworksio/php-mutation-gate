<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_key_exists;
use function array_merge;
use function array_values;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * Judges every tree whole, from the results of all its units, run, proved or
 * carried, against the higher of its declared floor and its baseline's; and,
 * in a change-scoped run, the mutants on the lines the change added or
 * modified against the floor for new code.
 */
final readonly class Judge
{
    private function __construct(
        private Trees $trees,
        private Baseline $baseline,
        private Reach $reach,
        private Uncovered $uncovered,
    ) {
    }

    public static function of(Trees $trees, Baseline $baseline, Reach $reach, Uncovered $uncovered): self
    {
        return new self($trees, $baseline, $reach, $uncovered);
    }

    /** Each tree, over every unit result it holds; a result no tree holds is judged in none. */
    public function trees(UnitResults $results): TreeVerdicts
    {
        $verdicts = [];

        foreach ($this->trees as $tree) {
            $verdicts[] = $this->tree($tree, $results);
        }

        return TreeVerdicts::of(...$verdicts);
    }

    /**
     * The mutants on changed lines, one set per package and floor: a tree's own
     * floor for new lines where it declares one, and this floor otherwise. A
     * change with no mutable lines has one empty set, which passes and says so.
     */
    public function newCode(TreeVerdicts $verdicts, Floor $floor): NewCodeVerdicts
    {
        /** @var array<string, NewCodeVerdict> $sets */
        $sets = [];

        foreach ($verdicts as $verdict) {
            $changed = $verdict->mutants()->changed();
            $package = $verdict->tree()->package();
            $held = $verdict->tree()->newCodeFloor();
            $set = $held instanceof Floor ? $held : $floor;
            $key = sprintf('%s %d', $package->path()->value(), $set->hundredths());
            $earlier = array_key_exists($key, $sets) ? $sets[$key]->mutants() : JudgedMutants::none();
            $sets[$key] = NewCodeVerdict::judged($package, $set, $earlier->and($changed), $this->uncovered);
        }

        return $this->mutable(array_values($sets), $floor);
    }

    private function tree(Tree $tree, UnitResults $results): TreeVerdict
    {
        $units = [];
        $mutants = [];

        foreach ($results as $result) {
            if ($this->trees->holding($result->unit()->path()) !== $tree) {
                continue;
            }

            $units[] = JudgedUnit::of($result->unit(), $result->origin());
            $mutants[] = [...$this->judged($result->mutants(), $result->flaky())];
        }

        $verdict = TreeVerdict::judged(
            $tree,
            $this->baseline->floorOf($tree->path()),
            JudgedUnits::of(...$units),
            JudgedMutants::of(...array_merge(...$mutants)),
            $this->uncovered,
        );
        $entry = $this->baseline->entryOf($tree->path());
        $lowering = $entry instanceof Entry ? $entry->lowering() : Unlowered::floor();

        return $lowering instanceof Lowered ? $verdict->withLowering($lowering) : $verdict;
    }

    /** Each mutant as its status reports it, or flaky where it gave two answers. */
    private function judged(Mutants $mutants, MutantIds $flaky): JudgedMutants
    {
        $judged = [];

        foreach ($mutants as $mutant) {
            $judged[] = JudgedMutant::of(
                $mutant,
                $flaky->has($mutant->id()) ? MutantJudgement::Flaky : MutantJudgement::reported($mutant->status()),
            );
        }

        return JudgedMutants::of(...$judged)->within($this->reach);
    }

    /** @param list<NewCodeVerdict> $sets */
    private function mutable(array $sets, Floor $floor): NewCodeVerdicts
    {
        $mutable = [];

        foreach ($sets as $set) {
            if ($set->mutants()->count() > 0) {
                $mutable[] = $set;
            }
        }

        return $mutable !== []
            ? NewCodeVerdicts::of(...$mutable)
            : NewCodeVerdicts::of(
                NewCodeVerdict::judged(Package::at(Path::root()), $floor, JudgedMutants::none(), $this->uncovered),
            );
    }
}
