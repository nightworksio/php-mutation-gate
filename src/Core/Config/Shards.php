<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** How the work is cut (ADR-0006): `shards.seconds`, `shards.max` and `costs.secondsPerLine`. */
final readonly class Shards
{
    public function __construct(private Seconds $seconds, private int $max, private Table $secondsPerLine)
    {
    }

    /** How long one shard is cut to take. */
    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    /** The most shards a plan cuts. */
    public function max(): int
    {
        return $this->max;
    }

    /** What a line costs before anything was measured, by path prefix. */
    public function secondsPerLine(): Table
    {
        return $this->secondsPerLine;
    }
}
