<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function count;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Proof\Unproved;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * Of the units a run considers, those a proof whose key still matches
 * already covers, and those left to run. The default branch's proof is taken
 * before the run's own scope's, and where the two disagree the unit runs. A
 * unit with no key always runs.
 */
final readonly class Proving
{
    private function __construct(private UnitResults $proved, private Units $toRun, private int $ownScope)
    {
    }

    /**
     * @param Proofs $defaultBranch the proofs of the default branch's ledger
     * @param Proofs $own           the proofs of the run's own scope, where that is not the default branch
     */
    public static function of(Units $considered, Keys $keys, Proofs $defaultBranch, Proofs $own): self
    {
        $proved = [];
        $toRun = [];
        $ownScope = 0;

        foreach ($considered as $unit) {
            $proof = self::proofOf($keys->keyOf($unit->path()), $defaultBranch, $own);

            if ($proof instanceof Proof) {
                $proved[] = UnitResult::held($unit, Origin::Proved, $proof->reported(), $proof->kills());
                $ownScope += $defaultBranch->has($proof->key()) ? 0 : 1;

                continue;
            }

            $toRun[] = $unit;
        }

        return new self(UnitResults::of(...$proved), Units::of(...$toRun), $ownScope);
    }

    public function proved(): UnitResults
    {
        return $this->proved;
    }

    public function toRun(): Units
    {
        return $this->toRun;
    }

    /** How many proofs it takes come from the run's own scope rather than the default branch's. */
    public function ownScopeProofs(): int
    {
        return $this->ownScope;
    }

    /**
     * The proof under a key, the default branch's before the run's own; none
     * where the two disagree, since the same code then gave two answers and
     * neither is used (ADR-0007, decision 3).
     */
    private static function proofOf(Digest|Unkeyed $key, Proofs $defaultBranch, Proofs $own): Proof|Unproved|Unkeyed
    {
        if (! $key instanceof Digest) {
            return $key;
        }

        $trusted = $defaultBranch->proofFor($key);
        $owned = $own->proofFor($key);

        return match (true) {
            $trusted instanceof Proof && $owned instanceof Proof
                && count($trusted->disagreeingWith($owned)) > 0 => Unproved::key($key),
            $trusted instanceof Proof => $trusted,
            default => $owned,
        };
    }
}
