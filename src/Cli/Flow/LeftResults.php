<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_values;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanJobs;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

use function sprintf;

/**
 * The result a shard left for the verdict, read from its file: one of
 * another plan, of another shard or of other units cannot stand for it, nor
 * one whose runner could not judge it or skipped mutants it kept no record
 * of. Where the plan's shards pull their work (ADR-0134), an item's result is
 * the first readable one any job left, its own job's first, each mutant
 * another job's run of it judged otherwise counted flaky.
 *
 * @internal
 */
final readonly class LeftResults
{
    private const string ANOTHER_SHARD = <<<'SAID'
        The result in the place of shard %d is the result of shard %d, so it cannot stand for it.
        Leave each shard's result under its own number.
        SAID;

    private const string OTHER_UNITS = <<<'SAID'
        The result of shard %d holds other units than the plan gives it, so it cannot stand for it.
        Run every shard of one plan, and the verdict with that plan.
        SAID;

    private const string ANOTHER_PLAN = <<<'SAID'
        The result of shard %d followed another plan than this one, so it cannot be merged into it.
        Run every shard of one plan, and the verdict with that plan.
        SAID;

    private const string RUNNER = 'Shard %d (%s) could not be judged: %s';

    private const string SKIPPED = <<<'SAID'
        Shard %d (%s) skipped %d mutants without a record of them, so they cannot be judged.
        The runner leaves no mutant unjudged in a run the gate judges.
        SAID;

    /**
     * A shard's result; or why it cannot be judged; or the file it is missing from.
     *
     * @return array{ShardResult, MutationResult}|CannotJudge|Path
     */
    public static function of(Plan $plan, Shard $shard, Path $directory, Directory $project): array|CannotJudge|Path
    {
        return $plan->shards()->pulls()
            ? self::pulledOf($plan, $shard, $directory, $project)
            : self::readOf($plan, $shard, Workspace::result($directory, $shard->id()), $project);
    }

    /** @return array{ShardResult, MutationResult}|CannotJudge|Path the result; or the file it is missing from */
    private static function readOf(Plan $plan, Shard $shard, Path $file, Directory $project): array|CannotJudge|Path
    {
        $contents = $project->read($file);

        return $contents instanceof Contents ? self::resultOf($plan, $shard, $contents) : $file;
    }

    /**
     * An item's result where the plan's shards pull their work (ADR-0134):
     * the first readable one, its own job's before the others' in their
     * order, each mutant another job's run of it judged otherwise counted
     * flaky; or why none can be judged; or the file its own job's is
     * missing from, where no job left one.
     *
     * @return array{ShardResult, MutationResult}|CannotJudge|Path
     */
    private static function pulledOf(
        Plan $plan,
        Shard $item,
        Path $directory,
        Directory $project,
    ): array|CannotJudge|Path {
        $own = $item->worker()->number();
        $jobs = [$own, ...array_diff(array_keys(PlanJobs::of($plan)), [$own])];
        $read = [];

        foreach ($jobs as $job) {
            $one = self::readOf($plan, $item, Workspace::pulled($directory, $item->id(), ShardId::of($job)), $project);
            $read = $one instanceof Path ? $read : [...$read, $one];
        }

        $judged = array_values(array_filter($read, is_array(...)));

        return match (true) {
            $judged !== [] => self::againstTheOthers($judged[0], array_slice($judged, 1)),
            $read !== [] => $read[0],
            default => Workspace::pulled($directory, $item->id(), $item->worker()),
        };
    }

    /**
     * A result, each of its mutants that another run of the item judged otherwise, or did not judge, counted flaky.
     *
     * @param  array{ShardResult, MutationResult}       $stood
     * @param  list<array{ShardResult, MutationResult}> $others
     * @return array{ShardResult, MutationResult}
     */
    private static function againstTheOthers(array $stood, array $others): array
    {
        $statuses = self::statusesOf($stood[1]);
        $differ = [];

        foreach ($others as [, $outcome]) {
            foreach ($outcome->mutants() as $mutant) {
                $id = $mutant->id()->value();
                $differ = array_key_exists($id, $statuses) && $statuses[$id] === $mutant->status()
                    ? $differ
                    : [...$differ, $mutant->id()];
            }
        }

        return $differ === []
            ? $stood
            : [$stood[0]->withFlaky($stood[0]->flaky()->and(MutantIds::of(...$differ))), $stood[1]];
    }

    /**
     * Each mutant's status in a result, by its id.
     *
     * @return array<string, MutantStatus>
     */
    private static function statusesOf(MutationResult $result): array
    {
        $statuses = [];

        foreach ($result->mutants() as $mutant) {
            $statuses[$mutant->id()->value()] = $mutant->status();
        }

        return $statuses;
    }

    /** @return array{ShardResult, MutationResult}|CannotJudge */
    private static function resultOf(Plan $plan, Shard $shard, Contents $contents): array|CannotJudge
    {
        $result = ShardResultFile::decode($contents->text());

        $outcome = $result instanceof ShardResult ? $result->outcome() : $result;

        return match (true) {
            $result instanceof CannotJudge => $result,
            $result->plan()->value() !== $plan->digest()->value() => CannotJudge::because(
                sprintf(self::ANOTHER_PLAN, $shard->id()->number()),
            ),
            $result->shard()->number() !== $shard->id()->number() => CannotJudge::because(
                sprintf(self::ANOTHER_SHARD, $shard->id()->number(), $result->shard()->number()),
            ),
            ! $result->units()->equals($plan->keys()->of($shard->units())) => CannotJudge::because(
                sprintf(self::OTHER_UNITS, $shard->id()->number()),
            ),
            $outcome instanceof CannotJudge => CannotJudge::because(
                sprintf(self::RUNNER, $shard->id()->number(), $shard->label(), $outcome->why()),
            ),
            $outcome->skipped() > 0 => CannotJudge::because(
                sprintf(self::SKIPPED, $shard->id()->number(), $shard->label(), $outcome->skipped()),
            ),
            default => [$result, $outcome],
        };
    }
}
