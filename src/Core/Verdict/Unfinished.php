<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;

use function sprintf;

/**
 * The units with a mutant a time budget left unjudged (ADR-0008):
 * a timeout it had no time to run again, a survivor it had no time to
 * confirm, or a mutant of a carried result that no longer stands. Each counts
 * as not killed, and each unit fails the verdict, since a budgeted run never
 * passes a mutant it did not judge. So does each unit with a heavy mutant
 * `timeouts.heavyParts` stopped (ADR-0114), whatever the score comes to.
 *
 * @internal
 */
final readonly class Unfinished
{
    private const string SAID = <<<'SAID'
        The run's time left %2$d of the mutants of %1$s unjudged, so this run cannot pass it.
        More time judges them: %3$s, or a higher shards.jobLimit where a shard stopped at it.
        SAID;

    private const string CAPPED = <<<'SAID'
        timeouts.heavyParts left %2$d of the mutants of %1$s unjudged, so this run cannot pass it.
        A higher timeouts.heavyParts judges them.
        SAID;

    /** Why each unit with a mutant a time budget or the part cap left unjudged fails the verdict. */
    public static function failures(UnitResults $results, GateCommand $gate): Failures
    {
        $failures = Failures::none();

        foreach ($results as $result) {
            $left = self::leftIn($result);
            $said = sprintf(self::SAID, $result->unit()->path()->value(), $left, $gate->budgeted());
            $capped = self::cappedIn($result);
            $failures = $left > 0 ? $failures->with(Failure::that($said)) : $failures;
            $failures = $capped > 0
                ? $failures->with(Failure::that(sprintf(self::CAPPED, $result->unit()->path()->value(), $capped)))
                : $failures;
        }

        return $failures;
    }

    /** How many of a unit's mutants `timeouts.heavyParts` stopped. */
    private static function cappedIn(UnitResult $result): int
    {
        $capped = 0;

        foreach ($result->mutants() as $mutant) {
            $capped += self::isCapped($mutant) ? 1 : 0;
        }

        return $capped;
    }

    private static function isCapped(Mutant $mutant): bool
    {
        $reason = $mutant->reason();

        return $reason instanceof Reason && $reason->isAtThePartCap();
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
