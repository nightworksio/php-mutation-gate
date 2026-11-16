<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

/**
 * How much a unit risks, in the order a budgeted run takes units
 * (ADR-0008): the first case first. Only a quick round of `watch` has
 * saved files, and so units retested or saved (ADR-0146).
 */
enum Risk
{
    /** Its last recorded result is unsettled, and a test file saved since the round before runs it (ADR-0146). */
    case Retested;

    /** Its file was saved since the round before (ADR-0146). */
    case Saved;

    /** It has changed lines, whose mutants the new-code floor judges. */
    case ChangedLines;

    /** Its last recorded result has a survivor, or a mutant too slow or too heavy to judge. */
    case Unsettled;

    /** It has never been mutated. */
    case NeverMutated;

    /** The change reaches it through a changed test or test support. */
    case Reached;

    /** Everything else. */
    case Rest;
}
