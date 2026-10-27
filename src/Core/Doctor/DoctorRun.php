<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\GateCommand;

/**
 * The run of doctor itself: the instant it looks, the memory_limit of its own
 * process, the gate's, and the gate as it was started, which each fix names.
 */
final readonly class DoctorRun
{
    private function __construct(private DateTimeImmutable $now, private int $memoryLimit, private GateCommand $gate)
    {
    }

    /** At this instant, in a process PHP lets take this many bytes of memory, or any as -1. */
    public static function of(DateTimeImmutable $now, int $memoryLimit): self
    {
        return new self($now, $memoryLimit, GateCommand::installed());
    }

    /** The gate as what these observations came from was started; as Composer linked it where no run is told. */
    public static function gateOf(Observations $observed): GateCommand
    {
        $run = $observed->run();

        return $run instanceof self ? $run->gate : GateCommand::installed();
    }

    /** This, the gate started as this command. */
    public function startedAs(GateCommand $gate): self
    {
        return new self($this->now, $this->memoryLimit, $gate);
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
