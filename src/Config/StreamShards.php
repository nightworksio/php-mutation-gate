<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * What a run that splits its streams cuts its shards by, and where a run
 * that cuts its test files across them judges each mutant (ADR-0117,
 * ADR-0162, ADR-0174): `splitStream.sharding` and `splitStream.judging`.
 */
final readonly class StreamShards implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `splitStream.sharding` `tests`, as by default: each shard streams a part of the test files, timed alike. */
    public static function byTests(): self
    {
        return new self(Json::at('splitStream.sharding', 'tests'));
    }

    /** `splitStream.sharding` `tests-every-part`: the same, each held unit judged in every shard by its part. */
    public static function byTestsInEveryPart(): self
    {
        return new self(Json::at('splitStream.sharding', 'tests-every-part'));
    }

    /** `splitStream.sharding` `source`: each shard streams the test files that cover its own part of the source. */
    public static function bySource(): self
    {
        return new self(Json::at('splitStream.sharding', 'source'));
    }

    /**
     * `splitStream.judging` `whole`, as by default: each mutant judged in
     * the one part that owns it, by every test that covers it.
     */
    public static function judgedWhole(): self
    {
        return new self(Json::at('splitStream.judging', 'whole'));
    }

    /** `splitStream.judging` `parted`: each part that holds a test that covers a mutant judges it by its own tests. */
    public static function judgedParted(): self
    {
        return new self(Json::at('splitStream.judging', 'parted'));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
