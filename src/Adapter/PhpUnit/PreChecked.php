<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckable;
use NightWorksIO\MutationGate\Core\Analysis\PreCheckables;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

/**
 * The mutants the gate's own engine made, checked by static analysis before
 * their tests, in the gate, before any run forks (ADR-0020, decisions 11 and
 * 12): each covered mutant is offered, with the file as the engine wrote it
 * and its covering tests' own time; one the analyser rejects is killed by
 * static analysis, in no time, and never runs; the rest run.
 */
final readonly class PreChecked
{
    /**
     * @param list<Mutant>      $rejected killed by static analysis, in the order they were made
     * @param list<MadeMutant>  $left     the rest, to run, in the order they were made
     */
    private function __construct(public array $rejected, public array $left)
    {
    }

    /**
     * These mutants, checked side by side up to this many at once.
     *
     * @param list<MadeMutant> $made
     */
    public static function of(array $made, CoverageMap $map, PreChecker $checker, ProcessCount $side): self
    {
        $offered = [];

        foreach ($made as $mutant) {
            $offer = self::offer($mutant, $map);
            $offered = $offer instanceof PreCheckable ? [...$offered, $offer] : $offered;
        }

        $rejections = $checker->rejected(PreCheckables::of(...$offered), $side);
        $rejected = [];
        $left = [];

        foreach ($made as $mutant) {
            $rejection = $rejections->of($mutant->id());

            if ($rejection instanceof Rejection) {
                $rejected[] = self::unrun($mutant)->rejected($rejection);

                continue;
            }

            $left[] = $mutant;
        }

        return new self($rejected, $left);
    }

    /** A mutant as static analysis is offered it, with its covering tests' own time; none where no test covers it. */
    private static function offer(MadeMutant $mutant, CoverageMap $map): PreCheckable|NotGiven
    {
        $location = $mutant->location();
        $covering = $map->testsCoveringSpan($location->file(), $location->start(), $location->last());
        $tests = OwnTime::of($map, $covering);

        return count($covering) === 0 ? NotGiven::value() : PreCheckable::of(
            self::unrun($mutant),
            Checkable::inPlace($mutant->mutated()),
            $tests instanceof Seconds ? $tests : Seconds::of(0.0),
        );
    }

    /** A mutant the engine made, before any run: unjudged, in no time. */
    private static function unrun(MadeMutant $mutant): Mutant
    {
        return Mutant::of(
            $mutant->id(),
            $mutant->id()->value(),
            $mutant->location(),
            $mutant->mutation(),
            MutantStatus::Unjudged,
            Seconds::of(0.0),
        );
    }
}
