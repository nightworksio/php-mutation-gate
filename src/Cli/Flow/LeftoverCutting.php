<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function is_array;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverCut;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The shares a run's second wave judges (ADR-0190): the mutants each shard
 * left at its deadline, each predicted to take the seconds the plan's map
 * timed the tests covering its lines at, cut into shares of a job's
 * capacity: the job's limit less the time the shard took to start judging,
 * written where the second wave's jobs and the verdict read them.
 * A shard that left no result, or one that cannot be judged, has none cut;
 * a mutant the map has no time for is predicted to take none.
 *
 * @internal
 */
final readonly class LeftoverCutting
{
    public function __construct(private Adapters $adapters)
    {
    }

    /**
     * The shares cut from the results in this directory, for jobs of this
     * limit, written beside them; or why they could not be written.
     */
    public function cut(Plan $plan, Path $results, Path $coverage, Seconds $limit): LeftoverShares|CannotJudge
    {
        $map = $this->mapIn($coverage);
        $left = [];
        $capacity = [];

        foreach ($plan as $shard) {
            $read = LeftResults::of($plan, $shard, $results, $this->adapters->project);
            $number = $shard->id()->number();
            $left[$number] = is_array($read) ? self::leftIn($map, [...$read[1]->mutants()]) : [];
            $capacity[$number] = is_array($read) ? self::capacityOf($read[0], $limit) : $limit;
        }

        $shares = LeftoverCut::of($plan->digest(), $left, $capacity);
        $written = $this->adapters->project->write(LeftoverParts::file($results), Contents::of($shares->encode()));

        return $written instanceof Written ? $shares : $written;
    }

    /**
     * The mutants of a result left at its deadline, each with the seconds it is predicted to take.
     *
     * @param  list<Mutant>                 $mutants
     * @return list<array{LeftMutant, float}>
     */
    private static function leftIn(CoverageMap $map, array $mutants): array
    {
        $left = [];

        foreach ($mutants as $mutant) {
            if (OutOfTime::left($mutant)) {
                $left[] = [
                    LeftMutant::of($mutant->id(), $mutant->nativeId(), $mutant->location()->file()),
                    self::predicted($map, $mutant),
                ];
            }
        }

        return $left;
    }

    /** The seconds a job of this limit has to judge in, as long as the shard took to start judging is spent first. */
    private static function capacityOf(ShardResult $result, Seconds $limit): Seconds
    {
        foreach ($result->measured()->steps() as $step) {
            if ($step->step() === Step::Mutation) {
                return Seconds::of(max(0.0, $limit->seconds() - $step->since()->seconds()));
            }
        }

        return $limit;
    }

    /** The seconds the map timed the tests covering a mutant's lines at, together. */
    private static function predicted(CoverageMap $map, Mutant $mutant): float
    {
        $location = $mutant->location();
        $durations = $map->durations();
        $seconds = 0.0;

        foreach ($map->testsCovering($location->file(), $location->start(), $location->last()) as $test) {
            $timed = $durations->of($test);
            $seconds += $timed instanceof Seconds ? $timed->seconds() : 0.0;
        }

        return $seconds;
    }

    /** The plan's map in this directory; an empty one where none can be read. */
    private function mapIn(Path $directory): CoverageMap
    {
        $limits = Handoff::limits();
        $contents = $this->adapters->project->readAtMost(CoverageMapFile::in($directory), $limits->packed());
        $map = $contents instanceof Contents
            ? CoverageMapFile::decode($contents->text(), $limits, Vouching::by($this->adapters->maps))
            : CoverageMap::of();

        return $map instanceof CoverageMap ? $map : CoverageMap::of();
    }
}
