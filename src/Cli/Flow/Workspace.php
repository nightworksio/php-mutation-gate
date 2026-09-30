<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;

/**
 * Where the gate keeps what one job hands the next: the plan, the coverage
 * map it used, each shard's own map, and each shard's result.
 */
final readonly class Workspace
{
    private const string DIRECTORY = '.mutation-gate';

    public static function plan(): Path
    {
        return Path::of(sprintf('%s/plan.json', self::DIRECTORY));
    }

    public static function coverage(): Path
    {
        return Path::of(sprintf('%s/coverage', self::DIRECTORY));
    }

    /** The directory of the coverage map the plan hands one shard: the lines of that shard's files alone. */
    public static function shardCoverage(ShardId $shard): Path
    {
        return Path::of(sprintf('%s/coverage/shard-%d', self::DIRECTORY, $shard->number()));
    }

    public static function results(): Path
    {
        return Path::of(sprintf('%s/results', self::DIRECTORY));
    }

    /** Where a shard leaves its result, in a directory of results. */
    public static function result(Path $results, ShardId $shard): Path
    {
        return Path::of(sprintf('%s/%d.json', $results->value(), $shard->number()));
    }
}
