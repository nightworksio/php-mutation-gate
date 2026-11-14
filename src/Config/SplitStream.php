<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** Whether, and how, a patched Pest run judges its mutants in split streams (ADR-0117): `splitStream`. */
final readonly class SplitStream implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `splitStream.enabled`: a patched run judges its mutants in split streams. */
    public static function on(): self
    {
        return new self(Json::at('splitStream.enabled', value: true));
    }

    /** `splitStream.enabled` off, as by default: each mutant runs its own run. */
    public static function off(): self
    {
        return new self(Json::at('splitStream.enabled', value: false));
    }

    /** `splitStream.children`: the most children a stream holds at once; 1. */
    public static function children(int $most): self
    {
        return new self(Json::at('splitStream.children', $most));
    }

    /**
     * `splitStream.changeCheck`, as by default: a stream forks a pure
     * expression's mutant only where it changes the value.
     */
    public static function checkingChanges(): self
    {
        return new self(Json::at('splitStream.changeCheck', value: true));
    }

    /** `splitStream.changeCheck` off: a stream forks at a site's first execution in a test, whatever its value. */
    public static function forkingUnchecked(): self
    {
        return new self(Json::at('splitStream.changeCheck', value: false));
    }

    /** `splitStream.killersFirst`, as by default: a stream forks each mutant first at the test that last killed it. */
    public static function killersFirst(): self
    {
        return new self(Json::at('splitStream.killersFirst', value: true));
    }

    /** `splitStream.killersFirst` off: a stream forks every mutant at every covering test, in the suite's order. */
    public static function everyTestAlike(): self
    {
        return new self(Json::at('splitStream.killersFirst', value: false));
    }

    /** `splitStream.killerStreams`: a first-pass stream runs only the tests that last killed its mutants. */
    public static function killerStreams(): self
    {
        return new self(Json::at('splitStream.killerStreams', value: true));
    }

    /** `splitStream.killerStreams` off, as by default: a first-pass stream runs every test file of its part. */
    public static function wholeFirstPass(): self
    {
        return new self(Json::at('splitStream.killerStreams', value: false));
    }

    /**
     * `splitStream.bootOnce`: a stream boots a framework's application once
     * and forks each test from it; a mutant whose site runs during the boot
     * streams again with a boot in every test.
     */
    public static function bootOnce(): self
    {
        return new self(Json::at('splitStream.bootOnce', value: true));
    }

    /** `splitStream.bootOnce` off, as by default: each test boots its application as it does without the gate. */
    public static function bootPerTest(): self
    {
        return new self(Json::at('splitStream.bootOnce', value: false));
    }

    /**
     * `splitStream.reconnect`: a test that holds a connection of the
     * application's own to a database, outside a transaction and with no
     * state its session keeps, forks, each child on its own copy of it.
     */
    public static function reconnect(): self
    {
        return new self(Json::at('splitStream.reconnect', value: true));
    }

    /** `splitStream.reconnect` off, as by default: a test that holds such a connection forks nothing. */
    public static function connectionsFallBack(): self
    {
        return new self(Json::at('splitStream.reconnect', value: false));
    }

    /** `splitStream.sharding` `tests`, as by default: each shard streams a part of the test files, timed alike. */
    public static function shardingByTests(): self
    {
        return new self(Json::at('splitStream.sharding', 'tests'));
    }

    /** `splitStream.sharding` `source`: each shard streams the test files that cover its own part of the source. */
    public static function shardingBySource(): self
    {
        return new self(Json::at('splitStream.sharding', 'source'));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
