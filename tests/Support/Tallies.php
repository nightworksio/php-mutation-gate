<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\Cost\MutatorTally;

/** Mutator tallies a test writes as a ledger would. */
final class Tallies
{
    /** A tally of these counts, which make one. */
    public static function of(
        string $runner,
        string $mutator,
        int $mutants,
        int $killed,
        int $survived,
        int $ignored,
        float $seconds,
    ): MutatorTally {
        $tally = MutatorTally::written($runner, $mutator, $mutants, $killed, $survived, $ignored, $seconds);

        return $tally instanceof MutatorTally ? $tally : throw new LogicException('These counts make no tally.');
    }

    /** Tallies holding each of these. */
    public static function holding(MutatorTally ...$tallies): MutatorTallies
    {
        $held = MutatorTallies::none();

        foreach ($tallies as $tally) {
            $held = $held->with($tally);
        }

        return $held;
    }
}
