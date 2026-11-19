<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use const INF;

use function min;

use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * When a process is stopped, on a clock read in seconds: at its command's
 * deadline after it started, or, where its command is held to a grace, that
 * long past the time its pool starts no more commands, if that comes first,
 * when it is halted (ADR-0197); never where neither bounds it.
 *
 * @internal
 */
final readonly class Bounds
{
    private function __construct(private float $until, private bool $halts)
    {
    }

    /** The bounds of this command, started at this time in a pool that starts none from that one. */
    public static function of(ProcessCommand $command, float $started, float $startBy): self
    {
        $deadline = $command->deadline();
        $grace = $command->grace();
        $own = $deadline instanceof Seconds ? $started + $deadline->seconds() : INF;
        $halt = $grace instanceof Seconds ? $startBy + $grace->seconds() : INF;

        return new self(min($own, $halt), $halt < $own);
    }

    /** When it is stopped. */
    public function until(): float
    {
        return $this->until;
    }

    /** Whether it is then halted, before its own deadline. */
    public function halts(): bool
    {
        return $this->halts;
    }
}
