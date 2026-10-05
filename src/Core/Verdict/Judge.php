<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_key_exists;
use function array_values;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * Judges every tree whole, from the results of all its units, run, proved or
 * carried, against the higher of its declared floor and its baseline's; and,
 * in a change-scoped run, the mutants on the lines the change added or
 * modified against the floor for new code. Each unit keeps the run whose
 * proof it came from (ADR-0015, decision 8), and each mutant is judged by
 * the tests the kill matrix says cover it, of a held unit only those its
 * holding tests that run it are among (ADR-0004 decision 5, ADR-0005
 * decision 10).
 */
final readonly class Judge
{
    private function __construct(
        private Trees $trees,
        private Baseline $baseline,
        private Reach $reach,
        private Uncovered $uncovered,
        private MutantTriage $triage,
        private KillMatrix $matrix,
        private Ignoring $ignoring,
        private NamedMutators|NotGiven $only,
        private MutantIds $equivalent,
    ) {
    }

    public static function of(
        Trees $trees,
        Baseline $baseline,
        Reach $reach,
        Uncovered $uncovered,
        TimeoutMode $timeouts,
        Ignoring $ignoring,
    ): self {
        return new self(
            $trees,
            $baseline,
            $reach,
            $uncovered,
            MutantTriage::under($timeouts),
            KillMatrix::none(),
            $ignoring,
            NotGiven::value(),
            MutantIds::none(),
        );
    }

    /**
     * This judge, keeping only the mutants and kills these mutators made, as
     * a run of the security mutators alone judges its units (ADR-0021,
     * decision 20).
     */
    public function onlyMadeBy(NamedMutators $mutators): self
    {
        return clone($this, ['only' => $mutators]);
    }

    /**
     * This judge, with the survivors among these ids proven equivalent
     * (ADR-0013, decision 10); none are proven without them.
     */
    public function proving(MutantIds $equivalent): self
    {
        return clone($this, ['equivalent' => $equivalent]);
    }

    /** This judge, naming each mutant's judging tests from this kill matrix; none are named without one. */
    public function judging(KillMatrix $matrix): self
    {
        return clone($this, ['matrix' => $matrix]);
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

    /** The security set of each package (ADR-0021, decision 16), of the mutants these mutators made. */
    public function security(TreeVerdicts $verdicts, NamedMutators $mutators, Floor|Undeclared $floor): SecurityVerdicts
    {
        return SecurityJudge::of($this->baseline, $this->uncovered, $floor)->judged($verdicts, $mutators);
    }

    private function tree(Tree $tree, UnitResults $results): TreeVerdict
    {
        $units = [];
        $mutants = [];

        foreach ($results as $result) {
            if ($this->trees->holding($result->unit()->path()) !== $tree) {
                continue;
            }

            $unit = JudgedUnit::of($result->unit(), $result->origin());
            $run = $result->run();
            $units[] = $run instanceof Run ? $unit->withRun($run) : $unit;
            $mutants[] = $this->judged($result);
        }

        $verdict = TreeVerdict::judged(
            $tree,
            $this->baseline->floorOf($tree->path()),
            JudgedUnits::of(...$units),
            JudgedMutants::none()->and(...$mutants),
            $this->uncovered,
        );
        $entry = $this->baseline->entryOf($tree->path());
        $lowering = $entry instanceof Entry ? $entry->lowering() : Unlowered::floor();

        return $lowering instanceof Lowered ? $verdict->withLowering($lowering) : $verdict;
    }

    /**
     * Each mutant of a unit's result as its status reports it after triage,
     * or flaky where it gave two answers, then left out where an ignore names
     * it, with the tests that judged it, and proven equivalent where it
     * survived and is; and each kill a ledger proved.
     */
    private function judged(UnitResult $result): JudgedMutants
    {
        $flaky = $result->flaky();
        $judged = [];

        foreach ($result->mutants() as $mutant) {
            $one = $this->ignoring->judged(JudgedMutant::of(
                $mutant,
                $flaky->has($mutant->id()) ? MutantJudgement::Flaky : $this->triage->judged($mutant),
            ));
            $judged[] = $one->judgedBy($this->judgingTests($result, $one));
        }

        $proved = [];

        foreach ($result->kills() as $kill) {
            $proved[] = JudgedKill::of($kill);
        }

        $mutants = JudgedMutants::of(...$judged)
            ->provenEquivalent($this->equivalent)
            ->and(JudgedMutants::kills(...$proved))
            ->within($this->reach);

        return $this->only instanceof NamedMutators ? $mutants->madeBy($this->only) : $mutants;
    }

    /**
     * The tests that judged a mutant: those the kill matrix says cover it;
     * of a held unit, those of them its holding tests that run it are among.
     * A held unit's result that names no holding tests, as a proof of an
     * earlier gate does, names none.
     */
    private function judgingTests(UnitResult $result, JudgedMutant $mutant): TestIds
    {
        $covering = $this->matrix->coveredBy($mutant);

        if ($result->unit()->judgedBy() instanceof WholeSuite) {
            return $covering;
        }

        return $covering->among($result->judging());
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
