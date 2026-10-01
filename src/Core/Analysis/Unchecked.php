<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

/**
 * Why static analysis left a survivor unchecked (ADR-0020, decisions 7, 9
 * and 11): the survivor stays a survivor, and the run warns once for each
 * reason. A shard's result records it by its value.
 */
enum Unchecked: string
{
    /** The analyser could not say its name and version, so the run's key holds none, and nothing is checked. */
    case Unidentified = 'unidentified';

    /** The analyser's one run over the original files failed, so no finding of a mutant can be told new. */
    case NoWarmUp = 'no-warm-up';

    /** The survivor's file is outside the paths the analyser analyses. */
    case OutOfScope = 'out-of-scope';

    /** The runner could not give the survivor's mutated code: its file is gone, or its diff no longer applies. */
    case NoMutant = 'no-mutant';

    /** The file, printed as the runner prints its mutants, does not analyse as the file itself does. */
    case PrintDiffers = 'print-differs';

    /** The analyser's check of the survivor could not run. */
    case Failed = 'failed';

    /** The time budget ran out before the survivor's check. */
    case OutOfTime = 'out-of-time';

    /** Why these survivors were left unchecked, as the run's warning says it. */
    public function because(): string
    {
        return match ($this) {
            self::Unidentified => 'the analyser could not say its version',
            self::NoWarmUp => 'the analyser\'s run over the original files failed',
            self::OutOfScope => 'their files are outside the paths the analyser analyses',
            self::NoMutant => 'the runner could not give their mutated code',
            self::PrintDiffers => 'their files analyse differently once printed as the runner prints its mutants',
            self::Failed => 'the analyser could not check them',
            self::OutOfTime => 'the time budget ran out before their checks',
        };
    }
}
