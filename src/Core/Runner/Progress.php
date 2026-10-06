<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use const INF;

/**
 * A running process's progress under its silence limit, as the size of the
 * file the limit names, read time after time on a clock in seconds: it has
 * stalled once the file has not grown for the limit since it last grew, and
 * never before it first grows.
 */
final readonly class Progress
{
    private function __construct(private SilenceLimit $silence, private int $size, private float $since)
    {
    }

    /** A process's progress before its file has been read. */
    public static function under(SilenceLimit $silence): self
    {
        return new self($silence, 0, INF);
    }

    /** The file whose growth is the progress. */
    public function file(): string
    {
        return $this->silence->progress();
    }

    /** This progress, the file read at this size at this time: grown where it is larger than when it last grew. */
    public function read(int $size, float $now): self
    {
        return $size > $this->size ? new self($this->silence, $size, $now) : $this;
    }

    /** Whether, at this time, the file has not grown for the silence limit since it last grew. */
    public function hasStalled(float $now): bool
    {
        return $now - $this->since > $this->silence->limit()->seconds();
    }
}
