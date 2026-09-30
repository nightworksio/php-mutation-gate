<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_merge;
use function array_slice;
use function min;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Order\Bound;
use NightWorksIO\MutationGate\Core\Order\KillHistory;

use function usort;

/**
 * What a ledger keeps when it is written. A proof can only be hit by a run
 * at the base it was established at, so the ledger keeps the proofs of the
 * bases its runs saw most recently, and of those, the newest, up to a cap
 * that bounds the file whatever the runs do. Its kill history keeps the
 * mutants those proofs hold, and of those and of its functions the ones that
 * most recently learned a killer, each up to a cap of its own.
 */
final readonly class LedgerRetention
{
    /** How many of the most recently seen bases keep their proofs. */
    private const int BASES = 5;

    /** How many proofs are kept at most, the newest. */
    private const int PROOFS = 20_000;

    /** How many mutants keep their killers at most, the ones that learned one most recently. */
    private const int KILLED = 20_000;

    /** How many functions keep their killers at most, the ones that learned one most recently. */
    private const int FUNCTIONS = 5_000;

    /**
     * @param positive-int $killed
     * @param positive-int $functions
     */
    private function __construct(private int $bases, private int $proofs, private int $killed, private int $functions)
    {
    }

    /** The one retention every ledger is written with. */
    public static function standard(): self
    {
        return new self(self::BASES, self::PROOFS, self::KILLED, self::FUNCTIONS);
    }

    /** This retention, keeping no more than so many proofs, the newest. */
    public function keepingAtMost(int $proofs): self
    {
        return new self($this->bases, min($proofs, $this->proofs), $this->killed, $this->functions);
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

        return $ledger->killers()->keeping(
            MutantIds::of(...array_merge(...$held)),
            Bound::atMost($this->killed),
            Bound::atMost($this->functions),
        );
    }
}
