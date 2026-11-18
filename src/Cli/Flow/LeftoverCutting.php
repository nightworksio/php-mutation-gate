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
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\LeftForks;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverCut;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Proof\KillHistoryFile;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The shares a run's second wave judges (ADR-0190): the mutants each shard
 * left at its deadline, each with the test files covering its lines, a
 * test's class standing for its file, and its forks: at the likely killer
 * the shard's handed ledger names among its covering tests, or at every
 * covering test where it names none. They are cut, at the wall seconds the
 * job's processes take over them by the plan's map's times (ADR-0195), into
 * shares of a job's capacity: the job's limit less the time the shard took
 * to start judging, written where the second wave's jobs and the verdict
 * read them. A shard that left no result, or one that cannot be judged, has
 * none cut; a test the map has no time for is predicted to take none.
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
    public function cut(
        Plan $plan,
        Path $results,
        Path $coverage,
        Seconds $limit,
        StreamWall $wall,
    ): LeftoverShares|CannotJudge {
        $map = $this->mapIn($coverage);
        $left = [];
        $capacity = [];

        foreach ($plan as $shard) {
            $read = LeftResults::of($plan, $shard, $results, $this->adapters->project);
            $number = $shard->id()->number();
            $history = $this->historyIn($coverage, $shard->id());
            $left[$number] = is_array($read) ? $this->leftIn($map, $history, [...$read[1]->mutants()]) : [];
            $capacity[$number] = is_array($read) ? $this->capacityOf($read[0], $limit) : $limit;
        }

        $shares = LeftoverCut::of($plan->digest(), $left, $this->filesOf($map), $capacity, $wall);
        $written = $this->adapters->project->write(LeftoverParts::file($results), Contents::of($shares->encode()));

        return $written instanceof Written ? $shares : $written;
    }

    /**
     * The mutants of a result left at its deadline, each with the test files covering its lines and its forks.
     *
     * @param  list<Mutant>                                     $mutants
     * @return list<array{LeftMutant, list<string>, LeftForks}>
     */
    private function leftIn(CoverageMap $map, KillHistory $history, array $mutants): array
    {
        $left = [];

        foreach ($mutants as $mutant) {
            $left = OutOfTime::left($mutant) ? [...$left, $this->leftOf($map, $history, $mutant)] : $left;
        }

        return $left;
    }

    /**
     * A left mutant with the test files covering its lines and its forks.
     *
     * @return array{LeftMutant, list<string>, LeftForks}
     */
    private function leftOf(CoverageMap $map, KillHistory $history, Mutant $mutant): array
    {
        $location = $mutant->location();
        $durations = $map->durations();
        $files = [];
        $seconds = [];

        foreach ($map->testsCovering($location->file(), $location->start(), $location->last()) as $test) {
            $files[TestMethod::classOf($test)] = true;
            $timed = $durations->of($test);
            $seconds[$test->value()] = $timed instanceof Seconds ? $timed->seconds() : 0.0;
        }

        return [
            LeftMutant::of($mutant->id(), $mutant->nativeId(), $location->file()),
            array_keys($files),
            LeftForks::byKillers($history->likelyKillers($mutant->id(), Nameless::code()), $seconds),
        ];
    }

    /** The kill ledger handed to a shard in the coverage directory; none where it holds none, or cannot be read. */
    private function historyIn(Path $coverage, ShardId $shard): KillHistory
    {
        $file = KillHistoryFile::in(Path::of(sprintf('%s/shard-%d', $coverage->value(), $shard->number())));
        $contents = $this->adapters->project->read($file);
        $history = $contents instanceof Contents ? KillHistoryFile::decode($contents->text()) : KillHistory::none();

        return $history instanceof KillHistory ? $history : KillHistory::none();
    }

    /**
     * The seconds the map timed each test file's tests at together, a test's class standing for its file.
     *
     * @return array<string, float>
     */
    private function filesOf(CoverageMap $map): array
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
    private function capacityOf(ShardResult $result, Seconds $limit): Seconds
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
