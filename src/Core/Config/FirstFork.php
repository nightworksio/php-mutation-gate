<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `splitStream.firstFork`: where a first-pass stream forks a mutant the
 * ledger names no likely killer of (ADR-0187).
 */
enum FirstFork: string
{
    /** At the covering test of the shard's part the map timed fastest, the rest in the second pass. */
    case Cheapest = 'cheapest';

    /** At every covering test of the shard's part. */
    case Every = 'every';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Cheapest => 'StreamWork::firstForkAtTheCheapest()',
            self::Every => 'StreamWork::firstForkAtEvery()',
        };
    }
}
