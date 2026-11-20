<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

/** How an unmutated control ended (see Control). */
enum ControlEnd: string
{
    /** Its tests passed within its limit. */
    case Passed = 'passed';

    /** A test of it failed or errored, or its process ended before its tests did. */
    case Failed = 'failed';

    /** Its tests ran out of its limit. */
    case RanOut = 'ran-out';

    /** Its process ran out of the memory cap. */
    case OutOfMemory = 'out-of-memory';

    /** It never ran, for the reason its run gives. */
    case Unrun = 'unrun';

    /**
     * Its process was halted past the run's deadline, before its tests ended,
     * or the time left had no room to start it: it found nothing (ADR-0197,
     * ADR-0300).
     */
    case Halted = 'halted';
}
