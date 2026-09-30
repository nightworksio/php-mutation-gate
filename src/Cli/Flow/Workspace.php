<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace as GateDirectory;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;

/**
 * Where the gate keeps what one job hands the next: the plan, the coverage
 * map it used, each shard's own map, and each shard's result.
 */
final readonly class Workspace
{
    public static function plan(): Path
    {
        return Path::of(sprintf('%s/plan.json', GateDirectory::root()->value()));
    }

    public static function coverage(): Path
    {
        return Path::of(sprintf('%s/coverage', GateDirectory::root()->value()));
    }

    /** The directory of the coverage map the plan hands one shard: the lines of that shard's files alone. */
    public static function shardCoverage(ShardId $shard): Path
    {
        return Path::of(sprintf('%s/coverage/shard-%d', GateDirectory::root()->value(), $shard->number()));
    }

    /**
     * The directory of the coverage map the plan hands the verdict: the lines
     * of every unit it considered, run, proved or carried, for the kill matrix.
     */
    public static function verdictCoverage(): Path
    {
        return Path::of(sprintf('%s/coverage/verdict', GateDirectory::root()->value()));
    }

    /** Where the tests that hold a shard's units leave the map of their run on their own. */
    public static function heldCoverage(ShardId $shard): Path
    {
        return Path::of(sprintf('%s/held/shard-%d', GateDirectory::root()->value(), $shard->number()));
    }

    public static function results(): Path
    {
        return Path::of(sprintf('%s/results', GateDirectory::root()->value()));
    }

    /** Where a shard leaves its result, in a directory of results. */
    public static function result(Path $results, ShardId $shard): Path
    {
        return Path::of(sprintf('%s/%d.json', $results->value(), $shard->number()));
    }
}
