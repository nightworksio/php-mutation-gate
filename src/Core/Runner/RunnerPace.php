<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How a runner paces its work: `runner.recycleAfter`, how many runs a batch holds for each place, so that each place
 * has a queue of runs while a batch runs, and few stand idle as its last runs end; and `runner.warmSlack`, how long
 * a warm worker may run past the end and the longest run's limit, for its boot and its writing; and
 * `runner.workerWait`, how long the gate waits for a warm worker to start or to end, `timeouts.startUp` where no
 * layer sets it.
 */
final readonly class RunnerPace
{
    /** How many runs a batch holds for each place: with runs alike, its last run ends a thirty-second of its time. */
    public const int RECYCLE_AFTER = 16;

    /** How long a warm worker may run past the end and the longest run's limit, in seconds. */
    public const float WARM_SLACK = 60.0;

    /** @param positive-int $recycleAfter */
    private function __construct(
        private int $recycleAfter,
        private Seconds $warmSlack,
        private Seconds $workerWait,
    ) {
    }

    /** Pace of this many runs a place, one at the least, and this slack. */
    public static function of(int $recycleAfter, Seconds $warmSlack, Seconds $workerWait): self
    {
        return new self(max(1, $recycleAfter), $warmSlack, $workerWait);
    }

    public static function standard(): self
    {
        return new self(self::RECYCLE_AFTER, Seconds::of(self::WARM_SLACK), Seconds::of(MutantLimit::START_UP));
    }

    /** @return positive-int */
    public function recycleAfter(): int
    {
        return $this->recycleAfter;
    }

    public function warmSlack(): Seconds
    {
        return $this->warmSlack;
    }

    /** `runner.workerWait`: how long to wait for a warm worker to start or to end. */
    public function workerWait(): Seconds
    {
        return $this->workerWait;
    }
}
