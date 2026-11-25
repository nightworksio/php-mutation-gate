<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

/**
 * How many mutants a run's streams leave for a run of their own (ADR-0117),
 * which the run lists as those runs start (see StreamPasses).
 *
 * @internal
 */
final readonly class OwnRuns
{
    /**
     * @param list<string>                $mutants
     * @param array<string, list<string>> $covering each mutant's covering tests, by its id
     */
    public static function leftBy(StreamVerdicts $verdicts, array $mutants, array $covering): int
    {
        $own = 0;

        foreach ($mutants as $mutant) {
            $tests = array_key_exists($mutant, $covering) ? $covering[$mutant] : [];
            $own += $verdicts->runsItsOwn($mutant, $tests) ? 1 : 0;
        }

        return $own;
    }
}
