<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_key_exists;
use function array_last;
use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\Analysis\CheckableMutant;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Psr\Clock\ClockInterface;

/**
 * The room the time left has for static analysis's checks of survivors run
 * side by side (ADR-0020): each survivor needs its own check, and the first
 * of a file its runner prints the print's too, each as long as a check is
 * taken to be, the processes sharing them out.
 */
final readonly class CheckRoom
{
    /** The checks a survivor needs on its own. */
    private const int ALONE = 1;

    /** The checks the first survivor of a printed file needs: the print, then the mutant. */
    private const int WITH_ITS_PRINT = self::ALONE + 1;

    public function __construct(
        private Deadline|Unlimited $deadline,
        private ClockInterface $clock,
        private ProcessCount $side,
    ) {
    }

    /**
     * Of these survivors, in turn, those whose checks the time left has room
     * for, each check as long as this; every one without a deadline.
     *
     * @template T of CheckableMutant
     *
     * @param  list<T> $offered
     * @return list<T>
     */
    public function fitting(array $offered, Seconds $each): array
    {
        if (! $this->deadline instanceof Deadline || $offered === []) {
            return $offered;
        }

        $needed = $this->needed($offered);
        $room = $this->deadline->fitting(
            array_last($needed),
            Seconds::of($each->seconds() / $this->side->count()),
            $this->clock->now(),
        );

        return array_slice($offered, 0, count(array_filter($needed, static fn(int $checks): bool => $checks <= $room)));
    }

    /**
     * How many checks the survivors need, up to and with each in turn.
     *
     * @param  non-empty-list<CheckableMutant> $offered
     * @return non-empty-list<int>
     */
    private function needed(array $offered): array
    {
        $needed = [];
        $printed = [];
        $total = 0;

        foreach ($offered as $survivor) {
            $file = $survivor->mutant()->location()->file()->value();
            $first = $survivor->checkable()->original() instanceof Contents && ! array_key_exists($file, $printed);
            $printed[$file] = true;
            $total += $first ? self::WITH_ITS_PRINT : self::ALONE;
            $needed[] = $total;
        }

        return $needed;
    }
}
