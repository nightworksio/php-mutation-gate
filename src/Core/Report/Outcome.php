<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_column;
use function array_flip;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

/**
 * What the HTML report and the digest call what a mutant came to, the gate's
 * own judgement beside it as detail (ADR-0009): killed; escaped, a survivor
 * or any other mutant the score counts as not killed though its tests ran;
 * untested, which no test covers and so escaped too; timed out, a kill by
 * timeout or a timeout triage could not tell from a hang; errored, a mutant
 * that crashed its tests; and left out, which the score leaves out. Each is
 * graded for a reader new to mutation testing, with why. The cases are
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
            MutantJudgement::Ignored,
            MutantJudgement::IgnoredByMarker,
            MutantJudgement::Equivalent,
            MutantJudgement::FailsUnmutated => self::LeftOut,
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

    /**
     * How many mutants came to each outcome, by its name, every outcome named.
     *
     * @param  array<string, int> $judged how many came to each judgement, by its name
     * @return array<string, int>
     */
    public static function counts(array $judged): array
    {
        $counts = [];

        foreach (self::cases() as $outcome) {
            $counts[$outcome->value] = 0;
        }

        foreach ($judged as $judgement => $number) {
            $counts[self::of(MutantJudgement::from($judgement))->value] += $number;
        }

        return $counts;
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

    public function grade(): Grade
    {
        return match ($this) {
            self::Killed => Grade::Good,
            self::Escaped, self::Untested => Grade::Bad,
            self::TimedOut, self::Errored, self::LeftOut => Grade::NeedsAttention,
        };
    }

    /** Why the outcome is graded as it is, in a sentence a reader new to mutation testing follows. */
    public function why(): string
    {
        return Fit::plain(match ($this) {
            self::Killed => 'Your tests noticed these changes.',
            self::Escaped => <<<'TEXT'
                A test ran the changed code but did not notice the change, so a real bug there could slip
                through too.
                TEXT,
            self::Untested => 'No test runs this code, so nothing would notice a bug in it.',
            self::TimedOut => <<<'TEXT'
                Counted as caught, but each one slows the run; one the gate cannot tell from a hang counts as
                escaped.
                TEXT,
            self::Errored => 'Counted as caught, but a crash can hide whether a test really checks this code.',
            self::LeftOut => 'Not in the score: check that each one is left out on purpose.',
        });
    }

    /** The term that says what the outcome is. */
    public function term(): Term
    {
        return match ($this) {
            self::Killed => Term::Killed,
            self::Escaped => Term::Escaped,
            self::Untested => Term::Untested,
            self::TimedOut => Term::TimedOut,
            self::Errored => Term::Errored,
            self::LeftOut => Term::LeftOut,
        };
    }

    /** Where a mutant so judged goes among a file's, those that need a test first, as the cases are declared. */
    public function rank(): int
    {
        return array_flip(array_column(self::cases(), 'value'))[$this->value];
    }
}
