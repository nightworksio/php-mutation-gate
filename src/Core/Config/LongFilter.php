<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `pest.longFilter`: what a mutant's own run and its control select where the covering tests' filter cannot be
 * passed on a command line (ADR-0138).
 */
enum LongFilter: string
{
    /** Every test of the files the run loaded, and its control runs them again. */
    case Files = 'files';

    /** Exactly the covering tests, named in a file the patched plugin reads; the control runs the killers alone. */
    case Set = 'set';

    /** As `files`, its control running the loaded tests in the run's recorded order up to its last killer. */
    case UntilKiller = 'until-killer';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Files => 'Pest::longFilterByFiles()',
            self::Set => 'Pest::longFilterBySet()',
            self::UntilKiller => 'Pest::longFilterUntilKiller()',
        };
    }
}
