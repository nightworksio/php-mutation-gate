<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `splitStream.sharding`: what a run that splits its streams cuts its shards
 * by (ADR-0117, ADR-0162).
 */
enum StreamSharding: string
{
    /**
     * Each shard streams a part of the test files, timed alike, and every
     * mutant they reach; each held unit is judged whole, by every test that
     * holds it, in one shard alone.
     */
    case Tests = 'tests';

    /** The same, each held unit judged in every shard by the shard's part of the tests that hold it. */
    case TestsEveryPart = 'tests-every-part';

    /** Each shard streams the test files that cover its own part of the source. */
    case Source = 'source';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Tests => 'StreamShards::byTests()',
            self::TestsEveryPart => 'StreamShards::byTestsInEveryPart()',
            self::Source => 'StreamShards::bySource()',
        };
    }

    /** Whether each shard streams a part of the test files. */
    public function cutsTests(): bool
    {
        return $this !== self::Source;
    }

    /** Whether each held unit is judged whole in one shard alone. */
    public function holdsWhole(): bool
    {
        return $this === self::Tests;
    }
}
