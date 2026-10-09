<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_all;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Order\Lesson;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Agreement;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\NotRecorded;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Pruning\Outcomes;
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
    public function __construct(private Adapters $adapters, private Settings $settings)
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
        $written = $written->withLearned($written->analysers()->plus($results->checks()->histories()));
        $recordings = Recordings::of($plan, $fresh, $run);
        $proved = $this->proved($written, $plan, $recordings);
        $learned = $this->adapters->narrowing->isNone()
            ? $this->learned($this->survived($proved, $fresh), $results, $ledgers->timings(), $plan)
            : $proved;
        $ledger = $this->taught($learned, $fresh);

        if ($ledger instanceof CannotJudge) {
            return $ledger;
        }

        $runs = $passed instanceof Passed ? $ledger->runs()->passing($passed) : $ledger->runs();
        $runs = $lastRun instanceof LastRun && $this->judgedEvery($results, $recordings, $plan)
            ? $runs->lastRunAt($lastRun)
            : $runs->cutShort();

        return $this->adapters->proofs->write($scope, $ledger->withRuns($runs));
    }

    /**
     * Whether the run judged every unit it considered: no shard's budget
     * stopped it, no held unit's tests missed its lines, and every unit it ran
     * left a proof, or carries its pruned mutators' last results (ADR-0005,
     * decision 2; ADR-0025, decision 1).
     *
     * @param list<array{UnitResult, Proof|NotRecorded}> $recordings
     */
    private function judgedEvery(Results $results, array $recordings, Plan $plan): bool
    {
        $pruned = $plan->considered()->pruned()->files();

        return ! $results->wereCutShort()
            && count($results->misses()) === 0
            && array_all(
                $recordings,
                static fn(array $recording): bool => $recording[1] instanceof Proof
                    || $pruned->has($recording[0]->unit()->path()),
            );
    }

    /**
     * The ledger, having learned what each mutant the run judged itself came
     * to, by its runner and mutator, which decides what later runs prune
     * (ADR-0025, decision 2).
     */
    private function survived(Ledger $ledger, UnitResults $fresh): Ledger
    {
        $window = $this->settings->reach()->pruning()->window();
        $runner = $this->settings->runner()->name();

        return $ledger->withLearned($ledger->survival()->after($runner, $window, ...Outcomes::of($fresh)));
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
            ->withLearned($ledger->killers()->learnedFrom(...$lessons))
            ->keepingKillersIn($functions->files());
    }

    /**
     * The ledger, with what each shard taught the cost model of the units it
     * ran, timed by the map it was handed, each smoothed over the timing every
     * ledger read held of it: a unit its budget ran out before took none of
     * its time.
     */
    private function learned(Ledger $ledger, Results $results, Timings $held, Plan $plan): Ledger|CannotJudge
    {
        $pruned = $plan->considered()->pruned()->files();
        $handoff = new Handoff($this->adapters->project, Handoff::limits());

        foreach ($results->shards() as [$shard, $result, $mutated]) {
            $map = $handoff->read($shard->id());

            if ($map instanceof CannotJudge) {
                return $map;
            }

            $ran = $shard->units()->except($result->unjudged());
            $ledger = $ledger->withTimings($this->adapters->costs->learn(
                $ran->except($ran->within($pruned)),
                $mutated->mutants(),
                $map,
                $result->measured(),
            )->smoothedOver($held));
        }

        return $ledger;
    }
}
