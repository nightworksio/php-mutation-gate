<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_all;
use function count;

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
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\NotRecorded;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Recording;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timings;
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
 * shard taught the cost model, where it made mutants with every mutator and
 * judged them by every test, so that a narrowed run never stands in for a
 * unit's time (ADR-0021 decision 20, ADR-0025 decision 9), the time each
 * analyser's checks of the shards' survivors took, where the verdict
 * passed, the commit it judged, the check-run it reported under and how many
 * proofs of the scope's own ledger it used, and, where the run judged every
 * unit it considered and git can say what the commit it judged was made of,
 * that commit as the scope's last run, which any other run removes (ADR-0007,
 * decision 3).
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
        LastRun|CannotTell $lastRun,
    ): Written|NotWritten|ReadsOnly|CannotJudge {
        $scope = $ledgers->access()->writes();

        if (! $scope instanceof Scope) {
            return $scope;
        }

        $fresh = $this->checked($plan, $results, $ledgers);
        $written = $ledgers->written()->atBase($plan->base());
        $written = $written->withAnalysers($written->analysers()->plus($results->checks()->histories()));
        $recordings = $this->recordings($plan, $fresh, $run);
        $proved = $this->proved($written, $plan, $recordings);
        $learned = $this->adapters->narrowing->isNone()
            ? $this->learned($proved, $results, $ledgers->timings())
            : $proved;
        $ledger = $this->taught($learned, $fresh);

        if ($ledger instanceof CannotJudge) {
            return $ledger;
        }

        $runs = $passed instanceof Passed ? $ledger->runs()->passing($passed) : $ledger->runs();
        $runs = $lastRun instanceof LastRun && $this->judgedEvery($results, $recordings)
            ? $runs->lastRunAt($lastRun)
            : $runs->cutShort();

        return $this->adapters->proofs->write($scope, $ledger->withRuns($runs));
    }

    /**
     * Whether the run judged every unit it considered: no shard's budget
     * stopped it, no held unit's tests missed its lines, and every unit it ran
     * left a proof (ADR-0005, decision 2).
     *
     * @param list<array{UnitResult, Proof|NotRecorded}> $recordings
     */
    private function judgedEvery(Results $results, array $recordings): bool
    {
        return ! $results->wereCutShort()
            && count($results->misses()) === 0
            && array_all($recordings, static fn(array $recording): bool => $recording[1] instanceof Proof);
    }

    /**
     * Each unit the shards ran, with the proof it leaves under its key, or why it leaves none.
     *
     * @return list<array{UnitResult, Proof|NotRecorded}>
     */
    private function recordings(Plan $plan, UnitResults $fresh, Run $run): array
    {
        $recordings = [];

        foreach ($fresh as $result) {
            $path = $result->unit()->path();
            $recordings[] = [
                $result,
                Recording::of(
                    $plan->keys()->keyOf($path),
                    $path,
                    $result->mutants(),
                    $result->flaky(),
                    $run,
                    $this->inputsOf($plan, $result),
                ),
            ];
        }

        return $recordings;
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
     *
     * @param list<array{UnitResult, Proof|NotRecorded}> $recordings
     */
    private function proved(Ledger $ledger, Plan $plan, array $recordings): Ledger
    {
        foreach ($recordings as [$result, $proof]) {
            $key = $plan->keys()->keyOf($result->unit()->path());
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
     * ran, timed by the map it was handed, each smoothed over the timing every
     * ledger read held of it: a unit its budget ran out before took none of
     * its time.
     */
    private function learned(Ledger $ledger, Results $results, Timings $held): Ledger|CannotJudge
    {
        $handoff = new Handoff($this->adapters->project, Handoff::limits());

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
            )->smoothedOver($held));
        }

        return $ledger;
    }
}
