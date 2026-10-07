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

    /** It never ran, for the reason its run gives. */
    case Unrun = 'unrun';
}
