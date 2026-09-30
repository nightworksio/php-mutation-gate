<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;

use function sprintf;

/**
 * The units with a mutant a time budget left unjudged (ADR-0008, decision 1):
 * a timeout it had no time to run again, a survivor it had no time to
 * confirm, or a mutant of a carried result that no longer stands. Each counts
 * as not killed, and each unit fails the verdict, since a budgeted run never
 * passes a mutant it did not judge.
 */
final readonly class Unfinished
{
    private const string SAID = <<<'SAID'
        The time budget left %2$d of the mutants of %1$s unjudged, so this run cannot pass it.
        More time judges them: %3$s
        SAID;

    /** Why each unit with a mutant a time budget left unjudged fails the verdict. */
    public static function failures(UnitResults $results): Failures
    {
        $failures = Failures::none();

        foreach ($results as $result) {
            $left = self::leftIn($result);
            $said = sprintf(self::SAID, $result->unit()->path()->value(), $left, OutOfTime::MORE_TIME);
            $failures = $left > 0 ? $failures->with(Failure::that($said)) : $failures;
        }

        return $failures;
    }

    /** How many of a unit's mutants and kills a time budget left unjudged. */
    private static function leftIn(UnitResult $result): int
    {
        $left = 0;

        foreach ([...$result->mutants(), ...$result->kills()] as $mutant) {
            $left += OutOfTime::left($mutant) ? 1 : 0;
        }

        return $left;
    }
}
