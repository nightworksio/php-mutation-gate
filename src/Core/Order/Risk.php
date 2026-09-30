<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

/**
 * How much a unit risks, in the order a budgeted run takes units (ADR-0008,
 * decision 1): the first case first.
 */
enum Risk
{
    /** It has changed lines, whose mutants the new-code floor judges. */
    case ChangedLines;

    /** Its last recorded result has a survivor, or a mutant too slow to judge. */
    case Unsettled;

    /** It has never been mutated. */
    case NeverMutated;

    /** The change reaches it through a changed test or test support. */
    case Reached;

    /** Everything else. */
    case Rest;
}
