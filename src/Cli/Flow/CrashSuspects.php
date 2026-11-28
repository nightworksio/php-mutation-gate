<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_slice;
use function array_values;

use Closure;

use function count;
use function intdiv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a crashed run's unjudged mutants come to in the job that ran them
 * (ADR-0333): they are run again, and a run again that crashes too leaves
 * fewer of them, or is split in two halves, each run again in turn, until a
 * mutant whose run alone crashes is found. That mutant is left for its
 * unmutated control to judge (see CrashControls); every other is judged by
 * the run again that told of it. A mutant the time left has no room to run
 * again stays as the crash left it, for the wave after.
 *
 * @internal
 */
final readonly class CrashSuspects
{
    /**
     * @param Closure(Mutants): (Mutants|CannotJudge) $again these mutants run again in one run
     * @param Seconds                                 $each  the time a run again must have left to start
     */
    public function __construct(
        private Closure $again,
        private TimeLeft $time,
        private Seconds $each,
        private Stopwatch $stopwatch,
    ) {
    }

    /** The result, each mutant its crash left unjudged run again as the time left allows. */
    public function settled(MutationResult $result): MutationResult
    {
        $suspects = $this->crashedIn($result->mutants());

        if ($suspects === []) {
            return $result;
        }

        $from = $this->stopwatch->now();
        $settled = $this->judged($suspects);
        $this->stopwatch->stop(Step::Suspects, $from, count($suspects));

        return $result->withMutants($result->mutants()->replacing(Mutants::of(...$settled)));
    }

    /**
     * These mutants run again: each as the run again told of it, the one
     * whose run alone crashed marked so, and each no time was left for as it
     * is.
     *
     * @param  list<Mutant> $suspects
     * @return list<Mutant>
     */
    private function judged(array $suspects): array
    {
        if ($suspects === [] || ! $this->time->fits($this->each)) {
            return $suspects;
        }

        $again = ($this->again)(Mutants::of(...$suspects));

        if ($again instanceof CannotJudge) {
            return $suspects;
        }

        $crashed = $this->crashedIn($again);

        return match (true) {
            $crashed === [] => [...$again],
            count($suspects) === 1 => [$suspects[0]->unjudged(OutOfTime::CrashedAlone)],
            count($crashed) < count($suspects) => [...$this->toldIn($again), ...$this->judged($crashed)],
            default => $this->halved($suspects),
        };
    }

    /**
     * These mutants, more than one, run again as two halves.
     *
     * @param  list<Mutant> $suspects
     * @return list<Mutant>
     */
    private function halved(array $suspects): array
    {
        $half = intdiv(count($suspects) + 1, 2);

        return [
            ...$this->judged(array_slice($suspects, 0, $half)),
            ...$this->judged(array_slice($suspects, $half)),
        ];
    }

    /**
     * Those of these mutants a crashed run left unjudged.
     *
     * @return list<Mutant>
     */
    private function crashedIn(Mutants $mutants): array
    {
        return array_values(array_filter([...$mutants], self::isCrashed(...)));
    }

    /**
     * Those of these mutants their run told of.
     *
     * @return list<Mutant>
     */
    private function toldIn(Mutants $mutants): array
    {
        return array_values(array_filter([...$mutants], static fn(Mutant $mutant): bool => ! self::isCrashed($mutant)));
    }

    private static function isCrashed(Mutant $mutant): bool
    {
        $reason = $mutant->reason();

        return $reason instanceof Reason && $reason->outOfTime() === OutOfTime::Crashed;
    }
}
