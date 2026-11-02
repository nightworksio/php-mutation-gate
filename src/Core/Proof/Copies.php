<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * Which copy of a scope's ledger a run reads, where the store's copy that
 * `fetch` wrote and the copy the run keeps beside it differ: `proofs.copies`.
 */
enum Copies: string
{
    /**
     * The copy whose latest record, of its proofs' runs, its timings and the
     * commit that passed, is the later; the store's where neither is.
     */
    case Newest = 'newest';

    /** The store's copy: the CI keeps its own copy only once the store holds the same. */
    case Together = 'together';

    /** Both copies read as one: every proof either holds, and the later copy's runs. */
    case Merged = 'merged';

    /** The ledger a run reads of these two copies of one scope's ledger. */
    public function chosen(Ledger $kept, Ledger $stored): Ledger
    {
        $keptIsLater = self::isLater($kept, $stored);

        return match ($this) {
            self::Newest => $keptIsLater ? $kept : $stored,
            self::Together => $stored,
            self::Merged => $keptIsLater ? $kept->and($stored) : $stored->and($kept),
        };
    }

    /** Whether one copy records a later moment than the other. */
    private static function isLater(Ledger $one, Ledger $other): bool
    {
        $at = self::newestIn($one);
        $than = self::newestIn($other);

        return $at instanceof Instant && (! $than instanceof Instant || $at->isAfter($than));
    }

    /**
     * The latest moment a ledger records: of its proofs' runs, its timings
     * and the commit that passed; none where it records none.
     */
    private static function newestIn(Ledger $ledger): Instant|NotGiven
    {
        $passed = $ledger->runs()->passed();
        $newest = $passed instanceof Passed ? $passed->at() : NotGiven::value();

        foreach ($ledger->proofs() as $proof) {
            $newest = self::later($newest, $proof->run()->at());
        }

        foreach ($ledger->timings() as $timing) {
            $newest = self::later($newest, $timing->at());
        }

        return $newest;
    }

    private static function later(Instant|NotGiven $newest, Instant $at): Instant
    {
        return $newest instanceof Instant && ! $at->isAfter($newest) ? $newest : $at;
    }
}
