<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Subsumption;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * A local run's results with each mutant its time budget left unrun saying
 * its kill is inferred, where a mutant that subsumes it was killed
 * (ADR-0079). It stays unjudged: an inferred kill is no proof, moves no
 * score and passes no verdict.
 *
 * @internal
 */
final readonly class Inference
{
    /** These results, inferred by what the ledgers learned of which mutant subsumes which, beside the run's others. */
    public static function in(UnitResults $left, Subsumption $learned, UnitResults $judged): UnitResults
    {
        $mutants = [];

        foreach ($judged->and($left) as $result) {
            $mutants = [...$mutants, ...$result->mutants()];
        }

        $offered = Mutants::of(...$mutants);
        $inferred = Subsumers::among($learned, ...$offered)->inferredFrom($offered);
        $results = UnitResults::none();

        foreach ($left as $result) {
            $replaced = [];

            foreach ($result->mutants() as $mutant) {
                $key = $mutant->id()->key();
                $reason = self::unrunReason($mutant);
                $replaced = $reason instanceof Reason && array_key_exists($key, $inferred)
                    ? [...$replaced, $mutant->because($reason->inferredFrom($inferred[$key]))]
                    : $replaced;
            }

            $results = $results->with($result->replacing(Mutants::of(...$replaced)));
        }

        return $results;
    }

    /** Why the budget left the mutant, where it ran out before the mutant ran at all; none otherwise. */
    private static function unrunReason(Mutant $mutant): Reason|NotGiven
    {
        $reason = $mutant->reason();

        if (! $reason instanceof Reason) {
            return NotGiven::value();
        }

        $before = $reason->outOfTime();

        return $before instanceof OutOfTime && $before->isUnrun() ? $reason : NotGiven::value();
    }
}
