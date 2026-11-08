<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_merge;
use function array_slice;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Config\Retained;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Order\Bound;
use NightWorksIO\MutationGate\Core\Order\KillHistory;

use const PHP_INT_MAX;

use function usort;

/**
 * What a ledger keeps when it is written. A proof can only be hit by a run
 * at the base it was established at, so the ledger keeps the proofs of the
 * bases its runs saw most recently, and of those, the newest, up to a cap
 * that bounds the file whatever the runs do. Its kill history keeps the
 * mutants those proofs hold, and of those and of its functions the ones that
 * most recently learned a killer, up to `proofs.keep.killers` and
 * `proofs.keep.functions`.
 *
 * @internal
 */
final readonly class LedgerRetention
{
    /** How many of the most recently seen bases keep their proofs. */
    public const int BASES = 5;

    /** How many proofs are kept at most, the newest. */
    public const int PROOFS = 20_000;

    /** How many functions keep their killers at most, the ones that learned one most recently. */
    public const int FUNCTIONS = 5_000;

    /** How many mutants keep their killers at most: the byte limits alone cap them. */
    private const int KILLED = PHP_INT_MAX;

    /**
     * @param positive-int $killed
     * @param positive-int $functions
     * @param positive-int $kills
     * @param positive-int $runs
     */
    private function __construct(
        private int $bases,
        private int $proofs,
        private int $killed,
        private int $functions,
        private int $kills,
        private int $runs,
    ) {
    }

    /** What every ledger is written with where the config says nothing. */
    public static function standard(): self
    {
        return new self(
            self::BASES,
            self::PROOFS,
            self::KILLED,
            self::FUNCTIONS,
            LimitHistory::KILLS,
            LimitHistory::RUNS,
        );
    }

    /** What `proofs.keep` says. */
    public static function kept(Retained $kept): self
    {
        return new self(
            $kept->bases(),
            $kept->proofs(),
            max(1, $kept->killers()),
            max(1, $kept->functions()),
            max(1, $kept->kills()),
            max(1, $kept->runs()),
        );
    }

    /** This retention, keeping no more than so many proofs, the newest. */
    public function keepingAtMost(int $proofs): self
    {
        return new self(
            $this->bases,
            min($proofs, $this->proofs),
            $this->killed,
            $this->functions,
            $this->kills,
            $this->runs,
        );
    }

    /** What a ledger keeps of the kills and the runs its limits are learned from: the newest of each. */
    public function limitsOf(Ledger $ledger): LimitHistory
    {
        return $ledger->learned()->limits()->keepingLast(Bound::atMost($this->kills), Bound::atMost($this->runs));
    }

    /** The bases whose proofs a ledger keeps: the ones its runs saw most recently. */
    public function basesOf(Ledger $ledger): Bases
    {
        return Bases::of(...array_slice([...$ledger->bases()], 0, $this->bases));
    }

    /**
     * The proofs a ledger keeps: those established at a kept base, the newest
     * first, at most so many; of two as new, the one held first.
     *
     * @return list<Proof>
     */
    public function proofsOf(Ledger $ledger): array
    {
        $bases = $this->basesOf($ledger);
        $kept = [];

        foreach ($ledger->proofs() as $proof) {
            if ($bases->has($proof->run()->base())) {
                $kept[] = $proof;
            }
        }

        usort(
            $kept,
            static fn(Proof $one, Proof $other): int => $other->run()->at()->value() <=> $one->run()->at()->value(),
        );

        return array_slice($kept, 0, $this->proofs);
    }

    /**
     * The kill history a ledger keeps: of the mutants its kept proofs hold,
     * and of its functions, those that learned a killer most recently, at
     * most so many of each.
     */
    public function killersOf(Ledger $ledger): KillHistory
    {
        $held = [];

        foreach ($this->proofsOf($ledger) as $proof) {
            $held[] = [...$proof->ids()];
        }

        return $ledger->learned()->killers()->keeping(
            MutantIds::of(...array_merge(...$held)),
            Bound::atMost($this->killed),
            Bound::atMost($this->functions),
        );
    }
}
