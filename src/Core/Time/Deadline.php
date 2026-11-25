<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

use function array_keys;
use function array_pad;

use DateTimeImmutable;

use function floor;
use function max;
use function min;

/**
 * When a budgeted process must stop (ADR-0008): its budget after it started.
 *
 * @internal
 */
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

    /** The deadline at this instant, in seconds since the epoch, as a wall clock reads it. */
    public static function atEpoch(float $at): self
    {
        return new self($at);
    }

    /** How much time is left at this moment, and none once the deadline has passed. */
    public function left(DateTimeImmutable $now): Seconds
    {
        return Seconds::of(max(0.0, $this->at - self::epochOf($now)));
    }

    /**
     * Of this many runs, each of which may take this long, run this many
     * side by side, round after round, how many fit in the time left at this
     * moment: none once the deadline has passed, and otherwise every one
     * where each takes no time.
     *
     * @param positive-int $abreast
     */
    public function fitting(int $wanted, Seconds $each, DateTimeImmutable $now, int $abreast = 1): int
    {
        return self::fittingIn($this->left($now), $wanted, $each, $abreast);
    }

    /**
     * Of this many runs, each of which may take this long, run this many
     * side by side, round after round, how many fit in this much time: none
     * where there is no time, and otherwise every one where each takes no
     * time.
     *
     * @param positive-int $abreast
     */
    public static function fittingIn(Seconds $left, int $wanted, Seconds $each, int $abreast = 1): int
    {
        return match (true) {
            $left->seconds() <= 0.0 => 0,
            $each->seconds() <= 0.0 => $wanted,
            default => min($wanted, (int) floor($left->seconds() / $each->seconds()) * $abreast),
        };
    }

    /**
     * Of these runs, in turn, each of which may take its own time, run this
     * many side by side, each started where the first of them is free, those
     * that end within the time left at this moment, by their places: none
     * once the deadline has passed. One that does not fit is passed over, so
     * a long run keeps no shorter one after it from running.
     *
     * @param  list<Seconds> $each
     * @param  positive-int  $abreast
     * @return list<int>
     */
    public function fittingEach(array $each, DateTimeImmutable $now, int $abreast = 1): array
    {
        return self::fittingEachIn($this->left($now), $each, $abreast);
    }

    /**
     * Of these runs, in turn, each of which may take its own time, run this
     * many side by side, those that end within this much time, by their
     * places: none where there is no time.
     *
     * @param  list<Seconds> $each
     * @param  positive-int  $abreast
     * @return list<int>
     */
    public static function fittingEachIn(Seconds $left, array $each, int $abreast = 1): array
    {
        $busy = array_pad([], $abreast, 0.0);
        $fitting = [];

        foreach ($each as $place => $run) {
            $free = array_keys($busy, min($busy), strict: true)[0];
            $ends = $busy[$free] + $run->seconds();

            if ($left->seconds() > 0.0 && $ends <= $left->seconds()) {
                $busy[$free] = $ends;
                $fitting[] = $place;
            }
        }

        return $fitting;
    }

    /** How long past the deadline this moment is; none before it. */
    public function overrunAt(DateTimeImmutable $moment): float
    {
        return max(0.0, self::epochOf($moment) - $this->at);
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
