<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `splitStream.judging`: where a run that cuts its test files across its
 * shards judges each mutant (ADR-0174).
 */
enum StreamJudging: string
{
    /**
     * In the one part that owns it, by every test that covers it: the part
     * of its likely killer, or of its first covering test where the ledger
     * names none.
     */
    case Whole = 'whole';

    /** In every part that holds a test that covers it, by that part's tests. */
    case Parted = 'parted';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Whole => 'StreamShards::judgedWhole()',
            self::Parted => 'StreamShards::judgedParted()',
        };
    }
}
