<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * How a pull request's run re-checks the last run's survivors first (ADR-0020,
 * ADR-0106): `survivorsFirst.max`, `survivorsFirst.budget` and
 * `survivorsFirst.shards`.
 */
final readonly class Survivors implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** At most this many, those on changed lines first; `0` re-checks none. */
    public static function firstAtMost(int $survivors): self
    {
        return new self(Json::at('survivorsFirst.max', $survivors));
    }

    /** Within this much time, as `"20m"` or `"1h"`; a file the re-check has no time left for is not run. */
    public static function firstWithin(string $budget): self
    {
        return new self(Json::at('survivorsFirst.budget', $budget));
    }

    /** Across at most this many jobs, from 1 to 20, each taking its share of the files. */
    public static function firstAcross(int $shards): self
    {
        return new self(Json::at('survivorsFirst.shards', $shards));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
