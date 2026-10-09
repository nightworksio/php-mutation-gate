<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

/** What one line of the results file the plugin writes, and the adapter reads, records. */
enum RecordEvent: string
{
    /** A mutant Pest made, with its file, lines, mutator, diff and mutated copy. */
    case Planned = 'planned';

    /**
     * A mutant Pest made that leaves its file as one made before it does, which
     * the patched generation loop keeps out of Pest's run, with the fields of a
     * planned one (ADR-0025, decision 13).
     */
    case Twin = 'twin';

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

    /** The seconds a patched run allowed a mutant's own run, by its mutated copy (see MutantTime). */
    case Limited = 'limited';

    /** The silence limit a patched run stopped a mutant's own run at, by its mutated copy (see Silence). */
    case Silent = 'silent';

    /** A mutant's own process had loaded the original file before Pest put the mutant in its place. */
    case Preloaded = 'preloaded';

    /** The arguments Pest started a mutant's own process with, before the plugin ordered its tests. */
    case Arguments = 'arguments';

    /** A replay of a kill's own run stopped after as many tests as the run ran, with their order (see ReplayStop). */
    case Stopped = 'stopped';

    /** How many tests a mutant's own process ran. */
    case Ran = 'ran';

    /** A mutant's own process ran out of its memory limit. */
    case Exhausted = 'exhausted';

    /** PHP recorded a fatal error in a mutant's own process, as the error log it kept says (see FatalError). */
    case Fatal = 'fatal';

    /** How a mutant's own process ended, as Pest's parent process saw it, by its mutated copy (see Ending). */
    case Ended = 'ended';

    /** The run reached its end. */
    case End = 'end';
}
