<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

/** A field of a line of the results file, as the plugin writes it and the adapter reads it. */
enum RecordField: string
{
    /** Which event the line records. */
    case Event = 'event';

    /** Pest's own id of a mutant. */
    case Id = 'id';

    /** A mutant's file, as Pest spells it on disk. */
    case File = 'file';

    /** The line a mutant starts on. */
    case Start = 'start';

    /** The line a mutant ends on. */
    case End = 'end';

    /** A mutant's mutator class. */
    case Mutator = 'mutator';

    /** Pest's diff of a mutant. */
    case Diff = 'diff';

    /** The mutated copy Pest serves in a mutant's own process. */
    case Mutated = 'mutated';

    /** How many mutants Pest made. */
    case Count = 'count';

    /** The opening run's seconds. */
    case Opening = 'opening';

    /** A mutant's status, as Pest names it. */
    case Status = 'status';

    /** How long a mutant ran. */
    case Duration = 'duration';

    /** A test that failed in a mutant's own process. */
    case Test = 'test';

    /** How many bytes the memory limit a mutant's own process ran out of holds. */
    case Bytes = 'bytes';
}
