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

    /** How many: the mutants Pest made, or the tests a mutant's own process ran. */
    case Count = 'count';

    /** The opening run's seconds. */
    case Opening = 'opening';

    /** A mutant's status, as Pest names it. */
    case Status = 'status';

    /** How long a mutant ran. */
    case Duration = 'duration';

    /** A test that failed in a mutant's own process. */
    case Test = 'test';

    /** The test files a mutant's own run loads, by their paths on disk. */
    case Files = 'files';

    /** The seconds a mutant's own run was allowed. */
    case Seconds = 'seconds';

    /** How many bytes the memory limit a mutant's own process ran out of holds. */
    case Bytes = 'bytes';

    /** How many tests a mutant's own process had started when the one a killer line names failed. */
    case At = 'at';

    /** The digest of the order a mutant's own process started its tests in, up to a killer (see OrderDigest). */
    case Order = 'order';

    /** The id of the mutant's own process a killer line came from. */
    case Run = 'run';

    /** The code a mutant's own process exited with. */
    case Code = 'code';

    /** Whether a signal ended a mutant's own process. */
    case Signalled = 'signalled';

    /** What a mutant's own process printed, on its output and then its error output, as much as evidence keeps. */
    case Printed = 'printed';
}
