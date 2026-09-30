<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_slice;
use function usort;

/**
 * What a ledger keeps when it is written. A proof can only be hit by a run
 * at the base it was established at, so the ledger keeps the proofs of the
 * bases its runs saw most recently, and of those, the newest, up to a cap
 * that bounds the file whatever the runs do.
 */
final readonly class LedgerRetention
{
    /** How many of the most recently seen bases keep their proofs. */
    private const int BASES = 5;

    /** How many proofs are kept at most, the newest. */
    private const int PROOFS = 20_000;

    private function __construct(private int $bases, private int $proofs)
    {
    }

    /** The one retention every ledger is written with. */
    public static function standard(): self
    {
        return new self(self::BASES, self::PROOFS);
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
}
