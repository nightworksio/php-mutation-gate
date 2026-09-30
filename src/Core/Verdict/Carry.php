<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/**
 * Whether a mutant of a result carried for a unit a time budget ran out
 * before still stands for the code on disk, or is unjudged, and why (ADR-0008,
 * decision 1). A mutant the score counts as not killed stands, which can only
 * make the verdict stricter. A kill stands only where every input its killing
 * run could have read is unchanged: a mutated run can reach code the
 * unmutated run never did, so the kill needs the whole base unchanged, and
 * each test that killed it, with the support it reads.
 */
enum Carry
{
    /** It stands as its result holds it. */
    case Stands;

    /** It was killed at another base: code outside the tests, the config, the runner or what is installed changed. */
    case OtherBase;

    /** It counts as killed, but no test is known to have killed it, as for a timeout or a crash. */
    case KillerUnknown;

    /** A test that killed it changed, with the support it reads, or is gone. */
    case KillerChanged;

    /** It was uncovered, and a test covers its line now. */
    case NowCovered;

    /** It was uncovered, and the run has no coverage map to say whether a test covers its line now. */
    case CoverageUnknown;
}
