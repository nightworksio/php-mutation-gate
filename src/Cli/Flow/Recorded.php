<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_all;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Untimed;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Agreement;
use NightWorksIO\MutationGate\Core\Proof\Judged;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\NotRecorded;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The ledger a verdict leaves in the scope the run writes: a proof of every
 * unit that ran to the end, at the base its keys were built on, what each
 * shard taught the cost model, where it made mutants with every mutator and
 * judged them by every test, so that a narrowed run never stands in for a
 * unit's time (ADR-0021, ADR-0025), the time each
 * analyser's checks of the shards' survivors took, what each mutator's
 * mutants cost and came to (ADR-0076), where the verdict
 * passed, the commit it judged, the check-run it reported under and how many
 * proofs of the scope's own ledger it used, and, where the run judged every
 * unit it considered and git can say what the commit it judged was made of,
 * that commit as the scope's last run, which any other run removes
 * (ADR-0007).
 *
 * @internal
 */
final readonly class Recorded
{
    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    public function write(
        Plan $plan,
        Results $results,
        JudgedMutants $judged,
        Ledgers $ledgers,
        Run $run,
        Passed|CannotTell $passed,
        LastRun|CannotTell $lastRun,
        RunTimings|Untimed $timings = new Untimed(),
        CoverageMap|CannotJudge|NotGiven $map = new NotGiven(),
        Instant|NotGiven $ended = new NotGiven(),
    ): Written|NotWritten|ReadsOnly|CannotJudge {
        $scope = $ledgers->access()->writes();

        if (! $scope instanceof Scope) {
            return $scope;
        }

        $fresh = $this->checked($plan, $results, $ledgers);
        $written = $ledgers->written()->atBase($plan->base());
        $written = $written->withLearned($written->learned()->analysers()->plus($results->checks()->histories()));
        $recordings = Recordings::of($plan, $fresh, $run, $this->adapters->project, $map);
        $ledger = new Learning($this->adapters, $this->settings)->learned(
            $this->proved($written, $plan, $recordings),
            $fresh,
            $results,
            $judged,
            $ledgers,
            $plan,
            $timings,
        );

        if ($ledger instanceof CannotJudge) {
            return $ledger;
        }

        $runs = $this->runs($ledger, $results, $recordings, $ledgers, $plan, $passed, $lastRun, $ended);
        $wrote = $this->adapters->proofs->write($scope, $ledger->withRuns($runs));

        if ($wrote instanceof Written) {
            new CachedPacks($this->adapters, $this->settings)->left(
                $ledger,
                $plan,
                $run,
                $scope->equals(Standing::planned($plan)->defaultBranch()),
            );
        }

        return $wrote;
    }

    /**
     * The ledger of the scope the run writes, holding this commit as the
     * newest that passed, for a run that judged nothing because the verdict
     * of the commit that passed before it stands: no proof, no lesson and no
     * last run, which any other run removes.
     */
    public function passing(Ledgers $ledgers, Passed $passed): Written|NotWritten|ReadsOnly
    {
        $scope = $ledgers->access()->writes();
        $ledger = $ledgers->written();

        return $scope instanceof Scope
            ? $this->adapters->proofs->write($scope, $ledger->withRuns($ledger->runs()->passing($passed)->cutShort()))
            : $scope;
    }

    /**
     * The runs the ledger records: this commit as the newest that passed,
     * where it did; as the scope's last run, where the run judged every unit
     * it considered, and otherwise none; and what it judged, where the run
     * records that (ADR-0007, ADR-0141).
     *
     * @param list<array{UnitResult, Proof|NotRecorded}> $recordings
     */
    private function runs(
        Ledger $ledger,
        Results $results,
        array $recordings,
        Ledgers $ledgers,
        Plan $plan,
        Passed|CannotTell $passed,
        LastRun|CannotTell $lastRun,
        Instant|NotGiven $ended,
    ): ScopeRuns {
        $runs = $passed instanceof Passed ? $ledger->runs()->passing($passed) : $ledger->runs();
        $runs = $lastRun instanceof LastRun && $this->judgedEvery($results, $recordings, $plan)
            ? $runs->lastRunAt($lastRun)
            : $runs->cutShort();

        return $ended instanceof Instant && $this->recordsJudged($ledgers, $plan)
            ? $runs->judging(Judged::of(
                $plan->commit(),
                $this->settings->ci()->check(),
                $ended,
                $this->open($plan, $ledger),
            ))
            : $runs;
    }

    /**
     * Whether the run judged every unit it considered: no shard's budget
     * stopped it, no sample left a unit out, no held unit's tests missed its
     * lines, and every unit it ran left a proof, or carries its pruned
     * mutators' last results (ADR-0005; ADR-0020;
     * ADR-0025).
     *
     * @param list<array{UnitResult, Proof|NotRecorded}> $recordings
     */
    private function judgedEvery(Results $results, array $recordings, Plan $plan): bool
    {
        $pruned = $plan->considered()->pruned()->files();

        return ! $results->wereCutShort()
            && ! $plan->considered()->sample()->isDrawn()
            && count($results->misses()) === 0
            && array_all(
                $recordings,
                static fn(array $recording): bool => $recording[1] instanceof Proof
                    || $pruned->has($recording[0]->unit()->path()),
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
     * Whether a run records what it judged: one on the default branch of the whole suite and every mutator, that
     * drew no sample (ADR-0141).
     */
    private function recordsJudged(Ledgers $ledgers, Plan $plan): bool
    {
        return ! $ledgers->readsOwnScope()
            && ! $plan->considered()->sample()->isDrawn()
            && ! $this->adapters->isSecurityOnly()
            && ! $this->adapters->isSuiteOnly();
    }

    /**
     * The units a plan ran that the ledger it wrote holds no proof of under their key: left unjudged, flaky,
     * unjudgeable or stopped by a budget (ADR-0141).
     */
    private function open(Plan $plan, Ledger $ledger): Paths
    {
        $open = [];

        foreach ($plan as $shard) {
            foreach ($shard->units() as $unit) {
                $key = $plan->keys()->keyOf($unit->path());

                if (! $key instanceof Digest || ! $ledger->proofs()->has($key)) {
                    $open[] = $unit->path();
                }
            }
        }

        return Paths::of(...$open);
    }
}
