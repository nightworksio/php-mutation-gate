<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_search;

use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

/**
 * The rule a tool that lists results reports a mutant counted as not killed
 * under: SARIF's rules, GitLab's check names and the problems output's
 * (ADR-0009, decision 2).
 */
enum ResultRule: string
{
    case Survived = 'survived';
    case Uncovered = 'uncovered';
    /** Unjudged mutants, and those too slow or too heavy to judge. */
    case Unjudged = 'unjudged';
    case Flaky = 'flaky';

    /** The rule of a mutant so judged; one the score does not count as not killed is never a result. */
    public static function of(MutantJudgement $judgement): self
    {
        return match ($judgement) {
            MutantJudgement::Uncovered => self::Uncovered,
            MutantJudgement::Unjudged,
            MutantJudgement::TooSlowToJudge,
            MutantJudgement::TooHeavyToJudge => self::Unjudged,
            MutantJudgement::Flaky => self::Flaky,
            MutantJudgement::Survived,
            MutantJudgement::Killed,
            MutantJudgement::KilledByStaticAnalysis,
            MutantJudgement::Errored,
            MutantJudgement::KilledByTimeout,
            MutantJudgement::KilledByMemoryCap,
            MutantJudgement::Ignored,
            MutantJudgement::IgnoredByMarker,
            MutantJudgement::Equivalent => self::Survived,
        };
    }

    /** What the rule reports. */
    public function text(): string
    {
        return match ($this) {
            self::Survived => 'A mutant no test fails on.',
            self::Uncovered => 'A mutant on a line no test runs.',
            self::Unjudged => 'A mutant the run did not judge, or could not judge in its time or memory limit.',
            self::Flaky => 'A mutant its tests killed on one run and not on another.',
        };
    }

    /** Where the rule is among every rule, which SARIF names it by beside its id. */
    public function index(): int
    {
        return (int) array_search($this, self::cases(), strict: true);
    }
}
