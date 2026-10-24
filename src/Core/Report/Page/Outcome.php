<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_column;
use function array_flip;

use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

/**
 * What the HTML report calls what a mutant came to, the gate's own judgement
 * beside it as detail (ADR-0009): killed; escaped, a survivor or any other
 * mutant the score counts as not killed though its tests ran; untested, which
 * no test covers and so escaped too; timed out, a kill by timeout or a
 * timeout triage could not tell from a hang; errored, a mutant that crashed
 * its tests; and left out, which the score leaves out. The cases are
 * declared in the order a file lists its mutants, those that need a test
 * first.
 */
enum Outcome: string
{
    case Escaped = 'escaped';
    case Untested = 'untested';
    case TimedOut = 'timed-out';
    case Errored = 'errored';
    case Killed = 'killed';
    case LeftOut = 'left-out';

    public static function of(MutantJudgement $judgement): self
    {
        return match ($judgement) {
            MutantJudgement::Killed, MutantJudgement::KilledByStaticAnalysis, MutantJudgement::KilledByMemoryCap
                => self::Killed,
            MutantJudgement::Survived,
            MutantJudgement::Unjudged,
            MutantJudgement::Flaky,
            MutantJudgement::TooHeavyToJudge => self::Escaped,
            MutantJudgement::Uncovered => self::Untested,
            MutantJudgement::KilledByTimeout, MutantJudgement::TooSlowToJudge => self::TimedOut,
            MutantJudgement::Errored => self::Errored,
            MutantJudgement::Ignored, MutantJudgement::IgnoredByMarker, MutantJudgement::Equivalent => self::LeftOut,
        };
    }

    /**
     * The outcomes the overview counts, in its order.
     *
     * @return list<self>
     */
    public static function counted(): array
    {
        return [self::Killed, self::Escaped, self::Untested, self::TimedOut, self::Errored];
    }

    public function label(): string
    {
        return match ($this) {
            self::Killed => 'Killed',
            self::Escaped => 'Escaped',
            self::Untested => 'Untested',
            self::TimedOut => 'Timed out',
            self::Errored => 'Errored',
            self::LeftOut => 'Left out',
        };
    }

    /** Where a mutant so judged goes among a file's, those that need a test first, as the cases are declared. */
    public function rank(): int
    {
        return array_flip(array_column(self::cases(), 'value'))[$this->value];
    }
}
