<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

use DateTimeImmutable;

use function floor;
use function max;
use function min;

/** When a budgeted process must stop (ADR-0008, decision 1): its budget after it started. */
final readonly class Deadline
{
    /** @param float $at the instant, in seconds since the epoch */
    private function __construct(private float $at)
    {
    }

    /** The deadline of a process that started at this moment with this budget. */
    public static function after(DateTimeImmutable $started, Seconds $budget): self
    {
        return new self(self::epochOf($started) + $budget->seconds());
    }

    /** How much time is left at this moment, and none once the deadline has passed. */
    public function left(DateTimeImmutable $now): Seconds
    {
        return Seconds::of(max(0.0, $this->at - self::epochOf($now)));
    }

    /**
     * Of this many runs, each of which may take this long, how many fit in
     * the time left at this moment: every one, where each takes no time.
     */
    public function fitting(int $wanted, Seconds $each, DateTimeImmutable $now): int
    {
        return $each->seconds() <= 0.0
            ? $wanted
            : min($wanted, (int) floor($this->left($now)->seconds() / $each->seconds()));
    }

    /** Whether the deadline has passed at this moment. */
    public function hasPassed(DateTimeImmutable $now): bool
    {
        return $this->left($now)->seconds() <= 0.0;
    }

    private static function epochOf(DateTimeImmutable $moment): float
    {
        return (float) $moment->format('U.u');
    }
}
