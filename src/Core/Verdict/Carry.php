<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/**
 * Whether a mutant of a result carried for a unit a time budget ran out
 * before still stands for the code on disk, or is unjudged, and why (ADR-0008,
 * decision 1). A mutant the score counts as not killed stands, which can only
 * make the verdict stricter. A kill stands only where each test that killed
 * it is unchanged, with the support it reads, and either the whole base is
 * unchanged or nothing that changed since reaches its unit or those tests by
 * a name they use: a mutated run can reach code the unmutated run never did,
 * so no narrower bound than what the code names holds.
 */
enum Carry
{
    /** It stands as its result holds it. */
    case Stands;

    /** It was killed at another base, and its result records no commit to read what changed since. */
    case NoCommit;

    /** It was killed at another base, and git cannot say what changed since the commit its result records. */
    case ChangeUnknown;

    /** It was killed at another base, and what changed since reaches its unit or a test that killed it. */
    case Reached;

    /** It counts as killed, but no test is known to have killed it, as for a timeout or a crash. */
    case KillerUnknown;

    /** A test that killed it changed, with the support it reads, or is gone. */
    case KillerChanged;

    /**
     * A static analyser killed it, and its result does not record which file
     * the finding sits in, so what the rejection depended on is not known.
     */
    case RejectionUnplaced;

    /** It was uncovered, and a test covers its line now. */
    case NowCovered;

    /** It was uncovered, and the run has no coverage map to say whether a test covers its line now. */
    case CoverageUnknown;
}
