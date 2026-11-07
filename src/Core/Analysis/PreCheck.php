<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * Whether a mutant is also checked before its tests, where it pays
 * (ADR-0020, ADR-0077). Every survivor is checked after them whatever this
 * says.
 *
 * - A mutant whose judging tests take no longer than one check is checked
 *   only after them: a check saves at most the tests' time. That time is
 *   what judging the mutant is expected to cost (see JudgingTime).
 * - A mutator is checked before the tests until its history, with the checks
 *   this run already makes of it before their tests, holds this many checks
 *   of it, which is how its rate is learned.
 * - Then it is checked before them where its rejection rate times its
 *   judging tests' time is greater than one check's time: a check saves the
 *   tests' time for the mutants it rejects, so a mutator the analyser never
 *   rejected is checked only after them.
 *
 * Placement only judges: a mutant is killed whether the tests or the
 * analyser caught it, so no placement moves a score.
 *
 * @internal
 */
final readonly class PreCheck
{
    private const int STANDARD = 50;

    private function __construct(private int $learning, private JudgingTime $judging)
    {
    }

    /** Fifty checks of a mutator before its rate is read, weighed against every covering test's own time. */
    public static function standard(): self
    {
        return new self(self::STANDARD, JudgingTime::covering());
    }

    /**
     * Fifty checks of a mutator before its rate is read, weighed against its
     * covering tests' own time at the pace the plan learned kills take, where
     * it learned one (ADR-0077).
     */
    public static function briefed(Briefing $briefing): self
    {
        return new self(self::STANDARD, JudgingTime::atPace($briefing->learned()->pace()));
    }

    /**
     * Whether to check a mutant before the tests that judge it, whose own
     * time is these seconds, where one check takes these, and this run
     * already checks this many mutants of its mutator before their tests.
     */
    public function pays(
        AnalyserHistory $history,
        Mutation $mutation,
        Seconds $covering,
        Seconds $check,
        int $pending,
    ): bool {
        $rate = $history->rateOf($mutation);
        $tests = $this->judging->of($covering);

        return match (true) {
            $tests->seconds() <= $check->seconds() => false,
            $rate->checks() < $this->learning => $rate->checks() + $pending < $this->learning,
            default => $rate->saving($tests)->seconds() > $check->seconds(),
        };
    }
}
