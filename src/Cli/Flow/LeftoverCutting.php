<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_keys;
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
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The shares a run's second wave judges (ADR-0190): the mutants each shard
 * left at its deadline, each with the test files covering its lines, a
 * test's class standing for its file, cut by the seconds the plan's map
 * timed every test of those files at into shares of a job's capacity: the
 * job's limit less the time the shard took to start judging, written where
 * the second wave's jobs and the verdict read them.
 * A shard that left no result, or one that cannot be judged, has none cut;
 * a test file the map has no time for is predicted to take none.
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

        $shares = LeftoverCut::of($plan->digest(), $left, self::filesOf($map), $capacity);
        $written = $this->adapters->project->write(LeftoverParts::file($results), Contents::of($shares->encode()));

        return $written instanceof Written ? $shares : $written;
    }

    /**
     * The mutants of a result left at its deadline, each with the test files covering its lines.
     *
     * @param  list<Mutant>                         $mutants
     * @return list<array{LeftMutant, list<string>}>
     */
    private static function leftIn(CoverageMap $map, array $mutants): array
    {
        $left = [];

        foreach ($mutants as $mutant) {
            if (OutOfTime::left($mutant)) {
                $location = $mutant->location();
                $files = [];

                foreach ($map->testsCovering($location->file(), $location->start(), $location->last()) as $test) {
                    $files[TestMethod::classOf($test)] = true;
                }

                $left[] = [LeftMutant::of($mutant->id(), $mutant->nativeId(), $location->file()), array_keys($files)];
            }
        }

        return $left;
    }

    /**
     * The seconds the map timed each test file's tests at together, a test's class standing for its file.
     *
     * @return array<string, float>
     */
    private static function filesOf(CoverageMap $map): array
    {
        $durations = $map->durations();
        $files = [];

        foreach ($durations->tests() as $test) {
            $timed = $durations->of($test);
            $file = TestMethod::classOf($test);
            $files[$file] = (array_key_exists($file, $files) ? $files[$file] : 0.0)
                + ($timed instanceof Seconds ? $timed->seconds() : 0.0);
        }

        return $files;
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
