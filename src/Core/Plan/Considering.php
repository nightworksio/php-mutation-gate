<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Proof\NeverProved;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * Which units a run considers, and which it carries. A unit the change
 * reaches is considered. A unit it does not reach carries the newest result
 * for its path in the ledgers the run reads, the default branch's and its own
 * scope's, and a unit with none to carry is treated as reached, so a
 * change-scoped run on an empty ledger is a full one.
 */
final readonly class Considering
{
    private function __construct(private Units $considered, private UnitResults $carried, private int $ownScope)
    {
    }

    /**
     * @param Proofs $defaultBranch the proofs of the default branch's ledger
     * @param Proofs $own           the proofs of the run's own scope, where that is not the default branch
     */
    public static function of(Units $units, Reach $reach, Proofs $defaultBranch, Proofs $own): self
    {
        $considered = [];
        $carried = [];
        $ownScope = 0;
        $trustedProofs = $defaultBranch->newest();
        $ownProofs = $own->newest();

        foreach ($units as $unit) {
            $trusted = $trustedProofs->of($unit->path());
            $newest = self::newer($trusted, $ownProofs->of($unit->path()));

            if (! $reach->reaches($unit) && $newest instanceof Proof) {
                $carried[] = UnitResult::fromProof($unit, Origin::Carried, $newest);
                $ownScope += $newest === $trusted ? 0 : 1;

                continue;
            }

            $considered[] = $unit;
        }

        return new self(Units::of(...$considered), UnitResults::of(...$carried), $ownScope);
    }

    public function considered(): Units
    {
        return $this->considered;
    }

    public function carried(): UnitResults
    {
        return $this->carried;
    }

    /** How many results it carries come from the run's own scope rather than the default branch's. */
    public function ownScopeProofs(): int
    {
        return $this->ownScope;
    }

    /** The newer of two results for a path; the default branch's where they are as new. */
    private static function newer(Proof|NeverProved $trusted, Proof|NeverProved $own): Proof|NeverProved
    {
        return match (true) {
            ! $own instanceof Proof => $trusted,
            ! $trusted instanceof Proof => $own,
            default => $own->run()->at()->isAfter($trusted->run()->at()) ? $own : $trusted,
        };
    }
}
