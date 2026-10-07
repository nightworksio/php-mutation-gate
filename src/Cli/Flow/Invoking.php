<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;

use Closure;

use function count;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\TimeoutControls;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Psr\Clock\ClockInterface;

/**
 * One runner invocation of a shard, as the shard makes it: each survivor
 * run once more, where `flaky.confirmSurvivors` asks for it (ADR-0008). A
 * mutant whose time ran out is never run again; its tests run unmutated, as
 * its control, under the same limit (see TimeoutControls), unless
 * `timeouts.mode: unjudged` makes every timeout too slow to judge. Under a
 * budget, a survivor whose second run would not fit in the time left, at
 * `timeouts.seconds`, is not run again, and a timeout whose control would
 * not fit, at the longest limit of those controls, has none; each is
 * unjudged. Each step is timed on the shard's stopwatch.
 */
final readonly class Invoking
{
    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private ClockInterface $clock,
        private Deadline|Unlimited $deadline,
        private Stopwatch $stopwatch,
        private CoverageMap $map,
        private HeldCovered $held,
    ) {
    }

    /** One invocation, its survivors run once more; or the first cannot judge. */
    public function invoked(MutationRequest $request): Invoked|CannotJudge
    {
        $result = $this->mutated($request);
        $again = $result instanceof CannotJudge ? $result : $this->confirmed($result->mutants(), $request);
        $controlled = $again instanceof CannotJudge ? $again : $this->controlled($again->mutants, $request);

        return match (true) {
            $result instanceof CannotJudge => $result,
            $again instanceof CannotJudge => $again,
            $controlled instanceof CannotJudge => $controlled,
            default => new Invoked(
                MutationResult::of($controlled, $result->skipped())
                    ->withWarnings($result->warnings())
                    ->withEvidence($result->evidence()),
                $again->flaky,
            ),
        };
    }

    /** The runner's run of the request, the steps its time went to kept. */
    private function mutated(MutationRequest $request): MutationResult|CannotJudge
    {
        $from = $this->stopwatch->now();
        $result = $this->adapters->runner->mutate($request);

        if (! $result instanceof CannotJudge) {
            $this->stopwatch->ran($result->steps(), $from);
        }

        return $result;
    }

    /**
     * The request, timed by what the budget has left now, so a run again
     * ends by the deadline the invocation had to; as it was without a budget.
     */
    private function retimed(MutationRequest $request): MutationRequest
    {
        return $this->deadline instanceof Deadline
            ? $request->within($this->deadline->left($this->clock->now()))
            : $request;
    }

    /** How many runs, each of which may take this long, fit in the time left: every one without a budget. */
    private function fitting(int $wanted, Seconds $each): int
    {
        return $this->deadline instanceof Deadline
            ? $this->deadline->fitting($wanted, $each, $this->clock->now())
            : $wanted;
    }

    /**
     * These mutants, each unjudged by the budget running out before this.
     *
     * @param list<Mutant> $mutants
     */
    private function unjudged(array $mutants, OutOfTime $before): Mutants
    {
        return Mutants::of(...array_map(static fn(Mutant $mutant): Mutant => $mutant->unjudged($before), $mutants));
    }

    /**
     * Survivor confirmation (ADR-0008): each survivor run once more, alone,
     * in a fresh process (ADR-0023, decision 14) and by the same tests, where
     * `flaky.confirmSurvivors` asks for it, unless it is proven equivalent, so
     * no test can tell it from its original (ADR-0013, decision 10); the
     * verdict proves the survivors again itself.
     * Those killed the second time are flaky, and those the time left had no
     * room for are unjudged.
     */
    private function confirmed(Mutants $mutants, MutationRequest $request): Confirmed|CannotJudge
    {
        if (! $this->settings->triage()->confirmSurvivors()) {
            return new Confirmed($mutants, MutantIds::none());
        }

        $from = $this->stopwatch->now();
        $equivalent = new StaticEquivalence($this->adapters, $this->settings)->proven($mutants)->proven;
        $survived = $mutants->counting(MutantStatus::Survived);

        if ($survived > 0) {
            $this->stopwatch->stop(Step::Equivalence, $from, $survived);
        }

        $survivors = array_values(array_filter(
            [...$mutants],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived
                && ! $equivalent->has($mutant->id()),
        ));

        if ($survivors === []) {
            return new Confirmed($mutants, MutantIds::none());
        }

        $fitting = $this->fitting(count($survivors), $this->settings->triage()->limit());
        $left = $this->unjudged(array_slice($survivors, $fitting), OutOfTime::BeforeConfirming);
        $again = $this->again(
            Step::Survivors,
            $fitting,
            fn(): Mutants|CannotJudge => $this->adapters->runner->retry(
                $this->retimed($request)->across(Pool::of($request->pool()->processes(), Workers::Fresh)),
                Mutants::of(...array_slice($survivors, 0, $fitting)),
                $this->settings->triage()->most(),
            ),
        );

        return $again instanceof CannotJudge
            ? $again
            : new Confirmed($mutants->replacing($left), $this->killed($again));
    }

    /**
     * The mutants, each timeout judged by its unmutated control (see
     * TimeoutControls): those that fit in the time left run side by side, in
     * the request's pool, and the rest are unjudged.
     */
    private function controlled(Mutants $mutants, MutationRequest $request): Mutants|CannotJudge
    {
        if ($this->settings->triage()->timeouts() === TimeoutMode::Unjudged) {
            return $mutants;
        }

        $controls = TimeoutControls::of($mutants, $this->map, $this->held);
        $asked = [...$controls->asked()];

        if ($asked === []) {
            return $mutants;
        }

        $longest = max(array_map(static fn(Control $control): float => $control->limit()->seconds(), $asked));
        $fitting = $this->fitting(count($asked), Seconds::of($longest));
        $from = $this->stopwatch->now();
        $runs = $fitting === 0
            ? ControlRuns::none()
            : $this->adapters->runner->controls(
                $this->retimed($request),
                Controls::of(...array_slice($asked, 0, $fitting)),
            );

        if ($fitting > 0) {
            $this->stopwatch->stop(Step::Controls, $from, $fitting);
        }

        return $runs instanceof CannotJudge
            ? $runs
            : $controls->applied($mutants, $runs, Controls::of(...array_slice($asked, $fitting)));
    }

    /**
     * The mutants a step runs again, this many of them, timed as that step;
     * none, and no step, where none fit.
     *
     * @param Closure(): (Mutants|CannotJudge) $running
     */
    private function again(Step $step, int $fitting, Closure $running): Mutants|CannotJudge
    {
        if ($fitting === 0) {
            return Mutants::none();
        }

        $from = $this->stopwatch->now();
        $again = $running();
        $this->stopwatch->stop($step, $from, $fitting);

        return $again;
    }


    /** The ids of those of these mutants that were killed, by a test or by a static analyser. */
    private function killed(Mutants $mutants): MutantIds
    {
        return MutantIds::of(...array_map(
            static fn(Mutant $mutant): MutantId => $mutant->id(),
            array_filter(
                [...$mutants],
                static fn(Mutant $mutant): bool => $mutant->status()->answer() === MutantStatus::Killed,
            ),
        ));
    }
}
