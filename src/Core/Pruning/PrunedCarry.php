<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use function array_map;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * The last results a run carries for the mutators it left out of a unit
 * (ADR-0025, decision 1): of each unit the plan prunes, the newest full
 * result's mutants and kills of the pruned mutators, carried by mutant id,
 * where that result is still of the unit's code and mutant set; never a
 * mutant the run made itself, as a runner that is not patched makes them
 * all. A unit whose last result no longer stands carries nothing, and its
 * pruned mutators' mutants are missing from it.
 */
final readonly class PrunedCarry
{
    private function __construct(private Pruned $pruned, private NewestProofs $newest, private Digests|Undigested $now)
    {
    }

    public static function of(Pruned $pruned, NewestProofs $newest, Digests|Undigested $now): self
    {
        return new self($pruned, $newest, $now);
    }

    /** These fresh results, each unit the plan prunes with its pruned mutators' last results carried in. */
    public function into(UnitResults $fresh): UnitResults
    {
        if ($this->pruned->isNone() || ! $this->now instanceof Digests) {
            return $fresh;
        }

        $results = UnitResults::none();

        foreach ($fresh as $result) {
            $results = $results->with($this->carried($result, $this->now));
        }

        return $results;
    }

    private function carried(UnitResult $result, Digests $now): UnitResult
    {
        $path = $result->unit()->path();
        $proof = $this->newest->of($path);
        $pruned = $this->pruned->files()->has($path);

        return $pruned && $proof instanceof Proof && Pruner::stands($proof, $path, $now)
            ? $this->from($result, $proof)
            : $result;
    }

    /** A result, with the last result's mutants and kills of the pruned mutators that the run did not make. */
    private function from(UnitResult $result, Proof $proof): UnitResult
    {
        $made = array_map(static fn(Mutant $mutant): MutantId => $mutant->id(), [...$result->mutants()]);
        $ran = MutantIds::of(...$made);
        $mutants = [];
        $kills = [];

        foreach ($proof->reported() as $mutant) {
            $mutants = $this->isCarried($mutant, $ran) ? [...$mutants, $mutant] : $mutants;
        }

        foreach ($proof->kills() as $kill) {
            $kills = $this->isCarried($kill, $ran) ? [...$kills, $kill] : $kills;
        }

        return $mutants === [] && $kills === []
            ? $result
            : $result->carryingPruned(Mutants::of(...$mutants), ProvedKills::of(...$kills));
    }

    /** Whether a mutant of the last result is one of a pruned mutator, which the run did not make. */
    private function isCarried(Mutant|ProvedKill $mutant, MutantIds $ran): bool
    {
        return $this->pruned->mutators()->has(RunnerMutatorName::of($mutant->mutator())) && ! $ran->has($mutant->id());
    }
}
