<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_map;
use function array_slice;

use Closure;

use function count;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Runner\ControlsSideBySide;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Psr\Clock\ClockInterface;

use function round;

/**
 * One runner invocation of a shard, as the shard makes it: each survivor
 * proven equivalent where `equivalence.static` asks, each checked by static
 * analysis (see SurvivorChecking), but one another part of the test files
 * owns (ADR-0117), and each other one neither proves equivalent nor kills
 * run once more, where `flaky.confirmSurvivors` asks for it
 * (ADR-0008), so a survivor static analysis kills never runs again. A
 * mutant whose time ran out is never run again; its tests run unmutated, as
 * its control, under the same limit (see TimeoutControls), but for a heavy
 * one, whose tests run in parts with it served (see PartedRuns), unless
 * `timeouts.mode: unjudged` makes every timeout too slow to judge, and so do
 * the tests that killed each mutant a test is named for, a file's together
 * first and each kill's alone only where that does not pass (see KillControls),
 * and those of each mutant out of memory (see MemoryControls).
 * Under a budget, a survivor whose second run would not fit in the time
 * left, at `timeouts.seconds`, is not run again, and a mutant whose control
 * would not fit, at the longest limit of those controls, has none; each is
 * unjudged. The runner checks its mutants with static analysis before their
 * tests where it can and that pays (see PreChecking). Where
 * `pest.overlapSteps` asks, the controls start beside the survivors' runs
 * again, each in a share of the pool (see StepsOverlap). Each step is timed
 * on the shard's stopwatch.
 *
 * @internal
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
        private SurvivorChecking $checking,
        private PartedRuns $parted,
        private PreChecker $preChecker = new NoPreCheck(),
    ) {
    }

    /** One invocation, its survivors checked and run once more; or the first cannot judge. */
    public function invoked(MutationRequest $request): Invoked|CannotJudge
    {
        $result = $this->mutated($request);

        return $result instanceof CannotJudge ? $result : $this->triaged($result, $request);
    }

    /**
     * An invocation's mutants triaged: its survivors screened, each mutant
     * a control judges controlled, and the survivors a control left, as the
     * parts of a heavy mutant leave them, screened after it (ADR-0108).
     */
    private function triaged(MutationResult $result, MutationRequest $request): Invoked|CannotJudge
    {
        $overlap = $this->overlapping($result, $request);
        $elsewhere = $result->evidence()->ownedElsewhere($result->mutants());
        $first = $this->screened($result->mutants(), MutantIds::none(), $elsewhere, $overlap->confirming());

        if ($first instanceof CannotJudge) {
            $overlap->stop();

            return $first;
        }

        $controlled = $this->controlling()->controlled($first->mutants, $request, $result->evidence(), $overlap);

        if ($controlled instanceof CannotJudge) {
            return $controlled;
        }

        return $this->afterControls($first, $controlled, $result, $request);
    }

    /** The invocation, the survivors its controls left, as a heavy mutant's parts leave them, screened in turn. */
    private function afterControls(
        Screened $first,
        Controlled $controlled,
        MutationResult $result,
        MutationRequest $request,
    ): Invoked|CannotJudge {
        $settled = $first->settledIn($controlled->mutants);
        $late = count($settled) < count($controlled->mutants);
        $elsewhere = $result->evidence()->ownedElsewhere($result->mutants());
        $screened = $late
            ? $this->screened($controlled->mutants, $settled, $elsewhere, $request)
            : $first->controlled($controlled->mutants);
        $all = $late && $screened instanceof Screened ? $first->then($screened) : $screened;

        return $all instanceof CannotJudge ? $all : new Invoked(
            MutationResult::of($all->mutants, $result->skipped())
                ->withWarnings($result->warnings())
                ->withEvidence($controlled->evidence),
            $all->flaky,
            $all->equivalent,
            $all->checks,
            $all->checked,
            $all->unchecked,
            $result->evidence()->judgedInPart($result->mutants()),
        );
    }

    /**
     * These mutants' survivors, but those settled already, screened: those
     * proven equivalent where `equivalence.static` asks (ADR-0013), timed, so
     * none runs again and none dooms the run (see Doom), which the verdict
     * proves again itself; then checked by static analysis; then the rest run
     * once more. Those passed over, which another part of the test files
     * owns, are proven and checked there (ADR-0117), and run once more here.
     */
    private function screened(
        Mutants $mutants,
        MutantIds $settled,
        MutantIds $passedOver,
        MutationRequest $request,
    ): Screened|CannotJudge {
        $skipped = $settled->and($passedOver);
        $open = Mutants::of(
            ...array_filter([...$mutants], static fn(Mutant $mutant): bool => ! $skipped->has($mutant->id())),
        );
        $from = $this->stopwatch->now();
        $equivalent = new StaticEquivalence($this->adapters, $this->settings)->proven($open)->proven;
        $this->stopwatch->handled(Step::Equivalence, $from, $open->counting(MutantStatus::Survived));
        $checked = $this->checking->checked($mutants, $skipped);
        $again = $this->confirmed($checked->mutants, $equivalent->and($settled), $request);

        return $again instanceof CannotJudge ? $again : Screened::of($checked, $again, $equivalent);
    }

    /**
     * The invocation's controls started beside its survivors' runs again,
     * where `pest.overlapSteps` asks for it, the runner starts controls while
     * the gate goes on, no budget has to fit them, and there are both to run
     * in a pool of more than one place: each takes a share of the places, as
     * many as its share of the runs, at least one, and the controls are those
     * the mutants ask for before the survivors are screened, which screening
     * never takes from. Otherwise none, and the survivors run in the whole
     * pool.
     */
    private function overlapping(MutationResult $result, MutationRequest $request): StepsOverlap
    {
        $runner = $this->adapters->runner;
        $places = $request->pool()->processes()->count();
        $survivors = $result->mutants()->counting(MutantStatus::Survived);
        $asked = $runner instanceof ControlsSideBySide
            && $this->settings->toggles()->on(Toggle::PestOverlapSteps)
            && $this->settings->triage()->flaky()->confirmSurvivors()
            && $this->deadline instanceof Unlimited
            && $places > 1
            && $survivors > 0
                ? $this->controlling()->asked($result->mutants(), $request, $result->evidence())
                : [];

        if (! $runner instanceof ControlsSideBySide || $asked === []) {
            return StepsOverlap::none($request);
        }

        $share = (int) round($places * $survivors / ($survivors + count($asked)));
        $taken = ProcessCount::of(max(1, min($places - 1, $share)));
        $from = $this->stopwatch->now();
        $pending = $runner->controlsStarted(
            $request->across($request->pool()->besideTaking($taken)),
            Controls::of(...$asked),
        );

        return StepsOverlap::started(
            $pending,
            $from,
            count($asked),
            $request->across($request->pool()->takingOnly($taken)),
        );
    }

    /** The runner's run of the request, the steps its time went to kept. */
    private function mutated(MutationRequest $request): MutationResult|CannotJudge
    {
        $from = $this->stopwatch->now();
        $result = $this->adapters->runner->mutate($request, $this->preChecker);

        if (! $result instanceof CannotJudge) {
            $this->stopwatch->ran($result->steps(), $from);
        }

        return $result;
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
     * in the pool the tail runs in (ADR-0070) and by the same tests, where
     * `flaky.confirmSurvivors` asks for it, unless it is proven equivalent.
     * Those killed the second time are flaky, and those the time left had no
     * room for are unjudged.
     */
    private function confirmed(Mutants $mutants, MutantIds $equivalent, MutationRequest $request): Confirmed|CannotJudge
    {
        if (! $this->settings->triage()->flaky()->confirmSurvivors()) {
            return new Confirmed($mutants, MutantIds::none());
        }

        $survivors = array_filter(
            [...$mutants],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived
                && ! $equivalent->has($mutant->id()),
        );
        $fitting = $this->timeLeft()->fitting(count($survivors), $this->settings->triage()->limits()->limit());
        $left = $this->unjudged(array_slice($survivors, $fitting), OutOfTime::BeforeConfirming);
        $again = $this->again(
            Step::Survivors,
            $fitting,
            fn(): Mutants|CannotJudge => $this->adapters->runner->retry(
                $this->timeLeft()->retimed($request)->across($request->pool()->tail()),
                Mutants::of(...array_slice($survivors, 0, $fitting)),
                $this->settings->triage()->limits()->most(),
            ),
        );

        return $again instanceof CannotJudge
            ? $again
            : new Confirmed($mutants->replacing($left), $this->killed($again));
    }

    /** The time the budget leaves the invocation's runs. */
    private function timeLeft(): TimeLeft
    {
        return new TimeLeft($this->deadline, $this->clock);
    }

    /** The controls that judge the invocation's timeouts, kills and mutants out of memory. */
    private function controlling(): Controlling
    {
        return new Controlling(
            $this->adapters,
            $this->settings,
            $this->timeLeft(),
            $this->stopwatch,
            $this->map,
            $this->held,
            $this->parted,
        );
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
