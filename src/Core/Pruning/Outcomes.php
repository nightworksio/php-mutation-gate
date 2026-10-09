<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

use function strcmp;
use function usort;

/**
 * What the mutants a run judged itself came to, for their mutators' windows
 * (ADR-0025, decision 2), in the order of their ids so every run that judged
 * the same mutants learns the same: a kill of any kind, by a test, by static
 * analysis, by a timeout, by the memory cap or by an error, counts as killed;
 * a survivor, a flaky, an unjudged or an uncovered mutant let the change
 * through. A mutant an ignore marker or the runner left out, and one carried
 * from a last result, was not judged by the run and teaches nothing.
 */
final readonly class Outcomes
{
    /** @return list<Outcome> */
    public static function of(UnitResults $results): array
    {
        $outcomes = [];

        foreach ($results as $result) {
            foreach ($result->mutants() as $mutant) {
                $outcome = self::outcomeOf($mutant, $result);
                $outcomes = $outcome instanceof Outcome ? [...$outcomes, $outcome] : $outcomes;
            }
        }

        usort($outcomes, static fn(Outcome $one, Outcome $other): int => strcmp($one->mutant(), $other->mutant()));

        return $outcomes;
    }

    private static function outcomeOf(Mutant $mutant, UnitResult $result): Outcome|NotGiven
    {
        $id = $mutant->id()->value();
        $flaky = $result->flaky()->has($mutant->id());

        return match (true) {
            $result->carriedPruned()->has($mutant->id()) => NotGiven::value(),
            $flaky => Outcome::through($mutant->mutator(), $id),
            default => self::byStatus($mutant->status(), $mutant->mutator(), $id),
        };
    }

    private static function byStatus(MutantStatus $status, string $mutator, string $id): Outcome|NotGiven
    {
        return match ($status) {
            MutantStatus::Killed,
            MutantStatus::KilledByStaticAnalysis,
            MutantStatus::TimedOut,
            MutantStatus::OutOfMemory,
            MutantStatus::Errored => Outcome::killed($mutator, $id),
            MutantStatus::Survived, MutantStatus::Uncovered, MutantStatus::Unjudged => Outcome::through($mutator, $id),
            MutantStatus::IgnoredByMarker, MutantStatus::Skipped => NotGiven::value(),
        };
    }
}
