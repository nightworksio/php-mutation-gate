<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use const INF;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A running process's progress under its silence limit, as the size of what
 * shows it, such as the file the limit names or what the process printed,
 * read time after time on a clock in seconds: it has stalled once that has
 * not grown for the limit since it last grew, and never before it first
 * grows.
 */
final readonly class Progress
{
    private function __construct(private Seconds $limit, private int $size, private float $since)
    {
    }

    /** A process's progress under this silence limit, before anything has been read. */
    public static function under(Seconds $limit): self
    {
        return new self($limit, 0, INF);
    }

    /** This progress, read at this size at this time: grown where it is larger than when it last grew. */
    public function read(int $size, float $now): self
    {
        return $size > $this->size ? new self($this->limit, $size, $now) : $this;
    }

    /** Whether, at this time, it has not grown for the silence limit since it last grew. */
    public function hasStalled(float $now): bool
    {
        return $now - $this->since > $this->limit->seconds();
    }
}
