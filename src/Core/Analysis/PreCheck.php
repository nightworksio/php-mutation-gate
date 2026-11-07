<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * Whether a mutant is also checked before its tests, where it pays
 * (ADR-0020, ADR-0077). Every survivor is checked after them whatever this
 * says.
 *
 * - A mutant whose judging tests take no longer than one check is checked
 *   only after them: a check saves at most the tests' time.
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
    public const int STANDARD = 50;

    private function __construct(private int $learning)
    {
    }

    /** Fifty checks of a mutator before its rate is read. */
    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** So many checks of a mutator before its rate is read, as `staticCheck.preCheckAfter` says. */
    public static function after(int $checks): self
    {
        return new self($checks);
    }

    /**
     * Whether to check a mutant before the tests that judge it, which take
     * these seconds, where one check takes these, and this run already checks
     * this many mutants of its mutator before their tests.
     */
    public function pays(
        AnalyserHistory $history,
        Mutation $mutation,
        Seconds $tests,
        Seconds $check,
        int $pending,
    ): bool {
        $rate = $history->rateOf($mutation);

        return match (true) {
            $tests->seconds() <= $check->seconds() => false,
            $rate->checks() < $this->learning => $rate->checks() + $pending < $this->learning,
            default => $rate->saving($tests)->seconds() > $check->seconds(),
        };
    }
}
