<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use Psr\Clock\ClockInterface;

/**
 * A shard's watch, from when it began mutating, for the first survivor that leaves its run no way to pass
 * (ADR-0008, ADR-0103): what each invocation adds to what was spent, that survivor noted with how far in it was
 * found, and whether the shard stops there. A shard that watches for none reads no clock.
 *
 * @internal
 */
final readonly class DoomWatch
{
    private function __construct(
        private Doom|Undoomed $doom,
        private ClockInterface $clock,
        private DateTimeImmutable|NotGiven $started,
        private bool $stops,
    ) {
    }

    /** Watching from now, by this doom, stopping at the survivor it finds where asked. */
    public static function from(Doom|Undoomed $doom, ClockInterface $clock, bool $stops): self
    {
        return new self($doom, $clock, $doom instanceof Doom ? $clock->now() : NotGiven::value(), $stops);
    }

    /** What was spent, and this invocation of these units, with the first survivor that dooms the run noted. */
    public function after(Spent $spent, Units $units, Invoked $invoked): Spent
    {
        $started = $this->started;
        $after = $started instanceof DateTimeImmutable
            ? Seconds::between($started, $this->clock->now())
            : Seconds::none();

        return $spent->after($invoked)->watched($this->doom, $units, $invoked, $after, $this->stops);
    }

    /** Whether the shard stops before its next batch: it stops at a doom, and what was spent holds one. */
    public function stopsAfter(Spent $spent): bool
    {
        return $this->stops && $spent->doomed instanceof Doomed;
    }
}
