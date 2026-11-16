<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `splitStream.sharding`: what a run that splits its streams cuts its shards
 * by (ADR-0117).
 */
enum StreamSharding: string
{
    /** Each shard streams a part of the test files, timed alike, and every mutant they reach. */
    case Tests = 'tests';

    /** Each shard streams the test files that cover its own part of the source. */
    case Source = 'source';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Tests => 'SplitStream::shardingByTests()',
            self::Source => 'SplitStream::shardingBySource()',
        };
    }
}
