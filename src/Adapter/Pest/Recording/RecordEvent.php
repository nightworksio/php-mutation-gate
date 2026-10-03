<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

/** What one line of the results file the plugin writes, and the adapter reads, records. */
enum RecordEvent: string
{
    /** A mutant Pest made, with its file, lines, mutator, diff and mutated copy. */
    case Planned = 'planned';

    /** How many mutants Pest made, and the opening run's seconds. */
    case Made = 'made';

    /** A mutant's status as Pest decides it, before its duration is known. */
    case Outcome = 'outcome';

    /** A mutant's final status and duration. */
    case Finished = 'finished';

    /** A test that failed an assertion in a mutant's own process. */
    case Killed = 'killed';

    /** A test that errored in a mutant's own process, rather than fail an assertion. */
    case Errored = 'errored';

    /** The test files a mutant's own run was narrowed to load, by its mutated copy. */
    case Narrowed = 'narrowed';

    /** A mutant's own process had loaded the original file before Pest put the mutant in its place. */
    case Preloaded = 'preloaded';

    /** A mutant's own process ran out of its memory limit. */
    case Exhausted = 'exhausted';

    /** The run reached its end. */
    case End = 'end';
}
