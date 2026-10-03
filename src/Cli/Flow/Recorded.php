<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Order\Lesson;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Agreement;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Recording;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The ledger a verdict leaves in the scope the run writes: a proof of every
 * unit that ran to the end, at the base its keys were built on, what each
 * shard taught the cost model, the time each analyser's checks of the
 * shards' survivors took, and, where the verdict passed, the commit it
 * judged, the check-run it reported under and how many proofs of the scope's
 * own ledger it used.
 */
final readonly class Recorded
{
    public function __construct(private Adapters $adapters)
    {
    }

    public function write(
        Plan $plan,
        Results $results,
        Ledgers $ledgers,
        Run $run,
        Passed|CannotTell $passed,
    ): Written|NotWritten|ReadsOnly|CannotJudge {
        $scope = $ledgers->access()->writes();

        if (! $scope instanceof Scope) {
            return $scope;
        }

        $fresh = $this->checked($plan, $results, $ledgers);
        $written = $ledgers->written()->atBase($plan->base());
        $written = $written->withAnalysers($written->analysers()->plus($results->checks()->histories()));
        $ledger = $this->taught($this->learned($this->proved($written, $plan, $fresh, $run), $results), $fresh);

        if ($ledger instanceof CannotJudge) {
            return $ledger;
        }

        return $this->adapters->proofs->write(
            $scope,
            $passed instanceof Passed ? $ledger->withPassed($passed) : $ledger,
        );
    }

    /** Each unit the shards ran, with the mutants a proof under its key in either ledger disagrees on flaky. */
    private function checked(Plan $plan, Results $results, Ledgers $ledgers): UnitResults
    {
        return Agreement::checked(
            $results->units(),
            $plan->keys(),
            $ledgers->defaultBranch()->proofs(),
            $ledgers->own()->proofs(),
        );
    }

    /**
     * The ledger, with a proof of every unit that ran to the end under a key,
     * and without the proof under the key of one that did not: its result
     * and that proof are not both answers of the same code.
     */
    private function proved(Ledger $ledger, Plan $plan, UnitResults $fresh, Run $run): Ledger
    {
        foreach ($fresh as $result) {
            $path = $result->unit()->path();
            $key = $plan->keys()->keyOf($path);
            $inputs = $this->inputsOf($plan, $result);
            $proof = Recording::of($key, $path, $result->mutants(), $result->flaky(), $run, $inputs);
            $ledger = match (true) {
                $proof instanceof Proof => $ledger->withProof($proof->judgedBy($result->judging())),
                $key instanceof Digest => $ledger->withoutProof($key),
                default => $ledger,
            };
        }

        return $ledger;
    }

    /**
     * What a proof of a unit records of its inputs: its share of the plan's
     * digests, with each test file that killed one of its mutants, where the
     * plan names the test.
     */
    private function inputsOf(Plan $plan, UnitResult $result): Inputs|Undigested
    {
        $digests = $plan->digests();
        $killers = [];

        foreach ($result->mutants() as $mutant) {
            $killers = $mutant->status() === MutantStatus::Killed ? [...$killers, ...$mutant->killers()] : $killers;
        }

        $files = $this->filesOf(TestIds::of(...$killers), $plan->names());

        return $digests instanceof Digests ? $digests->inputsOf($result->unit()->path(), $files) : $digests;
    }

    /** The test files these tests are in, where their names say. */
    private function filesOf(TestIds $tests, TestNames|CannotJudge $names): Paths
    {
        $files = Paths::none();

        foreach ($tests as $test) {
            $named = $names instanceof TestNames ? $names->testOf($test) : $test;
            $files = $named instanceof TestName ? $files->with($named->file()) : $files;
        }

        return $files;
    }

    /**
     * The ledger, having learned the first killer of every mutant the shards
     * killed, in the function it is in, and keeping the killers of functions
     * only in the files that still exist (ADR-0013, decision 2).
     */
    private function taught(Ledger|CannotJudge $ledger, UnitResults $fresh): Ledger|CannotJudge
    {
        if ($ledger instanceof CannotJudge) {
            return $ledger;
        }

        $files = Paths::none();

        foreach ($ledger->killers()->functions() as $ranked) {
            $files = $files->with($ranked->function()->file());
        }

        foreach ($fresh as $result) {
            foreach ($result->mutants() as $mutant) {
                $files = $files->with($mutant->location()->file());
            }
        }

        $functions = SourceFunctions::read($this->adapters->project, $files);

        return $functions instanceof SourceFunctions ? $this->killedIn($ledger, $fresh, $functions) : $functions;
    }

    private function killedIn(Ledger $ledger, UnitResults $fresh, SourceFunctions $functions): Ledger
    {
        $lessons = [];

        foreach ($fresh as $result) {
            foreach ($result->mutants() as $mutant) {
                $lessons[] = Lesson::of($mutant, $functions->around($mutant));
            }
        }

        return $ledger
            ->withKillers($ledger->killers()->learnedFrom(...$lessons))
            ->keepingKillersIn($functions->files());
    }

    /**
     * The ledger, with what each shard taught the cost model of the units it
     * ran, timed by the map it was handed: a unit its budget ran out before
     * took none of its time.
     */
    private function learned(Ledger $ledger, Results $results): Ledger|CannotJudge
    {
        $handoff = new Handoff($this->adapters->project);

        foreach ($results->shards() as [$shard, $result, $mutated]) {
            $map = $handoff->read($shard->id());

            if ($map instanceof CannotJudge) {
                return $map;
            }

            $ledger = $ledger->withTimings($this->adapters->costs->learn(
                $shard->units()->except($result->unjudged()),
                $mutated->mutants(),
                $map,
                $result->measured(),
            ));
        }

        return $ledger;
    }
}
