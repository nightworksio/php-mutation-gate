<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * Whether a pull request's run is already certain to fail (ADR-0008,
 * decision 6), as the verdict will judge its mutants: a survivor that did
 * not prove flaky and that no ignore leaves out, in a tree held to 100, the
 * higher of its declared floor and the baseline's; or on a line the change
 * added or modified, where the new-code floor that line is held to, its
 * tree's own or the run's, is 100. A score of whole hundredths below 100 is
 * all one survivor leaves, so either floor fails. It judges only runs no
 * static analysis can clear a survivor of, so it proves none equivalent.
 */
final readonly class Doom
{
    private function __construct(
        private Trees $trees,
        private Baseline $baseline,
        private Reach $reach,
        private Floor $newCode,
        private Ignoring $ignoring,
    ) {
    }

    /** Judged over these trees, the committed baseline, the lines the change reached and the run's new-code floor. */
    public static function over(
        Trees $trees,
        Baseline $baseline,
        Reach $reach,
        Floor $newCode,
        Ignoring $ignoring,
    ): self {
        return new self($trees, $baseline, $reach, $newCode, $ignoring);
    }

    /** The first of these mutants of these units that makes the run certain to fail, in order; or none. */
    public function first(Units $units, Mutants $mutants, MutantIds $flaky): Doomed|Undoomed
    {
        foreach ($mutants as $mutant) {
            $unit = $this->unitOf($units, $mutant);
            $doomed = $unit instanceof Unit && $this->counts($mutant, $flaky)
                ? $this->doomedIn($unit, $mutant)
                : Undoomed::run();

            if ($doomed instanceof Doomed) {
                return $doomed;
            }
        }

        return Undoomed::run();
    }

    /** Whether the verdict counts the mutant as a survivor: not flaky and not ignored. */
    private function counts(Mutant $mutant, MutantIds $flaky): bool
    {
        $judged = $this->ignoring->judged(JudgedMutant::of(
            $mutant,
            $flaky->has($mutant->id()) ? MutantJudgement::Flaky : MutantJudgement::reported($mutant->status()),
        ));

        return $judged->judgement() === MutantJudgement::Survived;
    }

    /** The doom a survivor of this unit brings: by its tree's floor first, then by the new code's. */
    private function doomedIn(Unit $unit, Mutant $mutant): Doomed|Undoomed
    {
        $tree = $this->trees->holding($unit->path());

        if (! $tree instanceof Tree) {
            return Undoomed::run();
        }

        $floor = HeldFloor::of($tree->declared(), $this->baseline->floorOf($tree->path()))->floor();
        $held = $tree->newCodeFloor();
        $newCode = $held instanceof Floor ? $held : $this->newCode;
        $changed = JudgedMutant::of($mutant, MutantJudgement::Survived)->within($this->reach)->isOnChangedLine();

        return match (true) {
            $floor instanceof Floor && $this->isWhole($floor) => Doomed::of(
                $unit->path(),
                $mutant->id(),
                $tree->path(),
                $floor,
                DoomedBy::Tree,
            ),
            $changed && $this->isWhole($newCode) => Doomed::of(
                $unit->path(),
                $mutant->id(),
                $tree->path(),
                $newCode,
                DoomedBy::NewCode,
            ),
            default => Undoomed::run(),
        };
    }

    private function isWhole(Floor $floor): bool
    {
        return $floor->hundredths() === Floor::whole()->hundredths();
    }

    /** The unit a mutant is of: the one its file is, or is inside. */
    private function unitOf(Units $units, Mutant $mutant): Unit|Undoomed
    {
        foreach ($units as $unit) {
            if ($mutant->location()->file()->within($unit->path())) {
                return $unit;
            }
        }

        return Undoomed::run();
    }
}
