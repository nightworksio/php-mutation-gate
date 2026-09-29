<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** What mutating one unit took a runner, as a finished shard measured it. */
final readonly class Timing
{
    private function __construct(private Path $unit, private Seconds $seconds) {}

    public static function of(Path $unit, Seconds $seconds): self
    {
        return new self($unit, $seconds);
    }

    public function unit(): Path
    {
        return $this->unit;
    }

    public function seconds(): Seconds
    {
        return $this->seconds;
    }
}
