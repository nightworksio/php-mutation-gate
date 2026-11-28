<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;

/**
 * How many mutants a run's streams leave for a run of their own (ADR-0117),
 * which the run lists as those runs start (see StreamPasses): every one the
 * verdicts gave no result, for whatever reason, as the second wave prices
 * what was left by how many of them finished.
 *
 * @internal
 */
final readonly class OwnRuns
{
    /**
     * Of these mutants, the streams' verdicts given, how many have no result
     * yet: each runs a run of its own, whatever kept a stream from judging it.
     *
     * @param array<array-key, MutationTest> $mutants
     */
    public static function leftBy(array $mutants): int
    {
        $own = 0;

        foreach ($mutants as $test) {
            $own += $test->result() === MutationTestResult::None ? 1 : 0;
        }

        return $own;
    }
}
