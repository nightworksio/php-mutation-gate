<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Agreement;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Recording;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
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

        $fresh = $this->checked($plan, $results, $ledgers);
        $ledger = $this->taught(
            $this->learned($this->proved($ledgers->written()->atBase($plan->base()), $plan, $fresh, $run), $results),
            $fresh,
        );

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
            $proof = Recording::of($key, $path, $result->mutants(), $result->flaky(), $run);
            $ledger = match (true) {
                $proof instanceof Proof => $ledger->withProof($proof),
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
        $history = $ledger->killers();

        foreach ($fresh as $result) {
            foreach ($result->mutants() as $mutant) {
                $history = $history->learnedFrom($mutant, $functions->around($mutant));
            }
        }

        return $ledger->withKillers($history)->keepingKillersIn($functions->files());
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
