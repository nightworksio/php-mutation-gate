<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use DateTimeImmutable;

/** The run of doctor itself: the instant it looks, and the memory_limit of its own process, the gate's. */
final readonly class DoctorRun
{
    private function __construct(private DateTimeImmutable $now, private int $memoryLimit)
    {
    }

    /** At this instant, in a process PHP lets take this many bytes of memory, or any as -1. */
    public static function of(DateTimeImmutable $now, int $memoryLimit): self
    {
        return new self($now, $memoryLimit);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /** The bytes of memory PHP lets the gate's own process take; -1 for no limit. */
    public function memoryLimit(): int
    {
        return $this->memoryLimit;
    }
}
