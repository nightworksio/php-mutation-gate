<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_keys;
use function array_sum;
use function in_array;
use function is_array;
use function is_float;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\LeftForks;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\SecondWave;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\StreamedOut;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Proof\KillHistoryFile;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
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
 * read them; a test the map has no time for is predicted to take none.
 * Where the shards' results alone decide the verdict, as where a shard left
 * none, none is cut, saying why (ADR-0301). Where the plan's jobs run their
 * own tail, its shares are at most the pool's jobs, each its job's limit;
 * where they stop at their target and their shares come to more than the
 * pool, as `shards.tail` and `costs.mode` say, they are folded into at most
 * the pool's jobs that run their own tail to their job's limit (ADR-0303).
 *
 * @internal
 */
final readonly class LeftoverCutting
{
    public function __construct(private Adapters $adapters)
    {
    }

    /**
     * The shares cut from the results in this directory, for jobs that stop
     * as the plan and these shard settings leave them, written beside them;
     * or why they could not be written.
     */
    public function cut(
        Plan $plan,
        Path $results,
        Path $coverage,
        Shards $settings,
        StreamWall $wall,
    ): LeftoverShares|CannotJudge {
        $decided = Results::decided($plan, $results, $this->adapters->project);
        $shares = $decided instanceof NotGiven
            ? $this->shares($plan, $results, $coverage, $settings, $wall)
            : LeftoverShares::decided($plan->digest(), $decided);
        $written = $this->adapters->project->write(LeftoverParts::file($results), Contents::of($shares->encode()));

        return $written instanceof Written ? $shares : $written;
    }

    /** The shares cut from the results in this directory, for jobs that stop as the plan and these settings say. */
    private function shares(
        Plan $plan,
        Path $results,
        Path $coverage,
        Shards $settings,
        StreamWall $wall,
    ): LeftoverShares {
        $map = $this->mapIn($coverage);
        $left = [];
        $started = [];
        $jobs = [];

        foreach ($plan as $shard) {
            $read = LeftResults::of($plan, $shard, $results, $this->adapters->project);
            $number = $shard->id()->number();
            $history = $this->historyIn($coverage, $shard->id());
            $left[$number] = is_array($read) ? $this->leftIn($map, $history, $read[1], $read[0], $wall) : [];
            $started[$number] = is_array($read) ? $this->startedAfter($read[0]) : Seconds::none();
            $jobs += is_array($read) ? [$number => $read[0]->measured()->job()] : [];
        }

        return SecondWave::cut(
            $plan->digest(),
            $left,
            $this->filesOf($map),
            $started,
            $plan->briefing()->learned(),
            $settings,
            $wall,
            $jobs,
        );
    }

    /**
     * The mutants of a result left at its deadline, each with the test files covering its lines and its forks.
     *
     * @return list<array{LeftMutant, list<string>, LeftForks}>
     */
    private function leftIn(
        CoverageMap $map,
        KillHistory $history,
        MutationResult $judged,
        ShardResult $result,
        StreamWall $wall,
    ): array {
        $left = [];
        $mutants = [...$judged->mutants()];
        $own = StreamedOut::of($result, $mutants);

        foreach ($mutants as $mutant) {
            $left = OutOfTime::left($mutant) ? [...$left, $this->leftOf($map, $history, $mutant, $own, $wall)] : $left;
        }

        return $left;
    }

    /**
     * A left mutant with the test files covering its lines and its forks.
     *
     * @return array{LeftMutant, list<string>, LeftForks}
     */
    private function leftOf(
        CoverageMap $map,
        KillHistory $history,
        Mutant $mutant,
        StreamedOut $own,
        StreamWall $wall,
    ): array {
        $location = $mutant->location();
        $durations = $map->durations();
        $files = [];
        $seconds = [];

        foreach ($map->testsCovering($location->file(), $location->start(), $location->last()) as $test) {
            $files[TestMethod::classOf($test)] = true;
            $timed = $durations->of($test);
            $seconds[$test->value()] = $timed instanceof Seconds ? $timed->seconds() : 0.0;
        }

        $alone = $own->priceOf($mutant, array_sum($seconds), $wall);

        return [
            LeftMutant::of($mutant->id(), $mutant->nativeId(), $location->file()),
            array_keys($files),
            is_float($alone)
                ? LeftForks::alone($alone)
                : LeftForks::byKillers($history->likelyKillers($mutant->id(), Nameless::code()), $seconds),
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

    /**
     * How long the shard took to start judging what it left: to its own runs, where its streams left it any, as a
     * share's streams take as long over the same mutants before its own; else to its mutation step; none where
     * it recorded neither.
     */
    private function startedAfter(ShardResult $result): Seconds
    {
        $started = Seconds::none();

        foreach ($result->measured()->steps() as $step) {
            $begins = in_array($step->step(), [Step::Mutation, Step::OwnRuns], strict: true);
            $started = $begins ? $step->since() : $started;
        }

        return $started;
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
