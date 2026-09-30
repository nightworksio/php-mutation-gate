<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Recording;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The ledger a verdict leaves in the scope the run writes: a proof of every
 * unit that ran to the end, at the base its keys were built on, what each
 * shard taught the cost model, and, where the verdict passed, the commit it
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

        $ledger = $this->learned(
            $this->proved($ledgers->written()->atBase($plan->base()), $plan, $results, $run),
            $results,
        );

        if ($ledger instanceof CannotJudge) {
            return $ledger;
        }

        return $this->adapters->proofs->write(
            $scope,
            $passed instanceof Passed ? $ledger->withPassed($passed) : $ledger,
        );
    }

    /** The ledger, with a proof of every unit that ran to the end under a key. */
    private function proved(Ledger $ledger, Plan $plan, Results $results, Run $run): Ledger
    {
        foreach ($results->units() as $result) {
            $path = $result->unit()->path();
            $proof = Recording::of($plan->keys()->keyOf($path), $path, $result->mutants(), $result->flaky(), $run);
            $ledger = $proof instanceof Proof ? $ledger->withProof($proof) : $ledger;
        }

        return $ledger;
    }

    /** The ledger, with what each shard taught the cost model of its units, timed by the map it was handed. */
    private function learned(Ledger $ledger, Results $results): Ledger|CannotJudge
    {
        $handoff = new Handoff($this->adapters->project);

        foreach ($results->shards() as [$shard, $result, $mutated]) {
            $map = $handoff->read($shard->id());

            if ($map instanceof CannotJudge) {
                return $map;
            }

            $ledger = $ledger->withTimings($this->adapters->costs->learn(
                $shard->units(),
                $mutated->mutants(),
                $map,
                $result->measured(),
            ));
        }

        return $ledger;
    }
}
