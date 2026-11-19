<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a mutant came to its verdict in a run, whichever runner judged it
 * (ADR-0230): forked by a split stream in its first or its second pass
 * (ADR-0117, ADR-0196), run in a fresh process of its own because its file
 * was loaded before the tests began (ADR-0148), run in a process of its own,
 * or rejected by static analysis (ADR-0020).
 */
enum Route: string
{
    case StreamFirstPass = 'stream first pass';
    case StreamSecondPass = 'stream second pass';
    case FreshRun = 'fresh run';
    case OwnRun = 'own run';
    case StaticCheck = 'static check';

    /**
     * The route of a mutant of this status, given the route its runner named
     * and whether a split stream judged it: static check for a kill static
     * analysis made, the route its runner named where it named one, the
     * first pass of a stream that judged it, its own run otherwise; none
     * where no route ran it to its verdict.
     */
    public static function of(MutantStatus $status, self|NotGiven $named, bool $streamed): self|NotGiven
    {
        return match (true) {
            ! self::ran($status) => NotGiven::value(),
            $status === MutantStatus::KilledByStaticAnalysis => self::StaticCheck,
            $named instanceof self => $named,
            $streamed => self::StreamFirstPass,
            default => self::OwnRun,
        };
    }

    /** The route whose time this step is, where it is one route's alone. */
    public static function timing(Step $step): self|NotGiven
    {
        return match ($step) {
            Step::FirstPass => self::StreamFirstPass,
            Step::SecondPass => self::StreamSecondPass,
            Step::FreshRuns => self::FreshRun,
            Step::StaticCheck => self::StaticCheck,
            Step::HeldCoverage,
            Step::Coverage,
            Step::ValueReaderCoverage,
            Step::ValueReaders,
            Step::Preparing,
            Step::Mutation,
            Step::Reading,
            Step::TrialCoverage,
            Step::Trials,
            Step::Baselines,
            Step::Confirmation,
            Step::Equivalence,
            Step::Survivors,
            Step::Controls,
            Step::HeavyParts => NotGiven::value(),
        };
    }

    /** Whether a route ran a mutant that came to this status to it. */
    private static function ran(MutantStatus $status): bool
    {
        return match ($status) {
            MutantStatus::Killed,
            MutantStatus::KilledByStaticAnalysis,
            MutantStatus::Survived,
            MutantStatus::TimedOut,
            MutantStatus::Errored,
            MutantStatus::OutOfMemory,
            MutantStatus::FailsUnmutated => true,
            MutantStatus::Uncovered,
            MutantStatus::Unjudged,
            MutantStatus::IgnoredByMarker,
            MutantStatus::Skipped,
            MutantStatus::Equivalent,
            MutantStatus::NotReached => false,
        };
    }
}
