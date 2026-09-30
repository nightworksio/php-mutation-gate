<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * Whether a mutant is also checked before its tests, where it pays (ADR-0020,
 * decision 11). Every survivor is checked after them whatever this says.
 *
 * - A mutator is checked before the tests until its history holds this many
 *   checks of it, which is how its rate is learned.
 * - Then a mutator the analyser never rejected is checked only after them.
 * - Any other is checked before them where its rejection rate times its
 *   judging tests' time is greater than one check's time, measured or not
 *   yet: a check saves the tests' time for the mutants it rejects.
 *
 * Placement only judges: a mutant is killed whether the tests or the
 * analyser caught it, so no placement moves a score.
 */
final readonly class PreCheck
{
    private const int STANDARD = 50;

    private function __construct(private int $learning)
    {
    }

    /** Fifty checks of a mutator before its rate is read. */
    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** Whether to check a mutant before the tests that judge it, which take these seconds. */
    public function pays(AnalyserHistory $history, Mutation $mutation, Seconds $tests): bool
    {
        $rate = $history->rateOf($mutation);
        $check = $history->time()->each();

        return match (true) {
            $rate->checks() < $this->learning => true,
            $rate->rejections() === 0 => false,
            $check instanceof Unmeasured => true,
            default => $rate->saving($tests)->seconds() > $check->seconds(),
        };
    }
}
