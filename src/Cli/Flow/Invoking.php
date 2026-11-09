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
use function min;

use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\HeavyMutants;
use NightWorksIO\MutationGate\Core\Control\KillControls;
use NightWorksIO\MutationGate\Core\Control\MemoryControls;
use NightWorksIO\MutationGate\Core\Control\TimeoutControls;
use NightWorksIO\MutationGate\Core\Control\UnnamedKillControls;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ControlsSideBySide;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\UnfilteredRuns;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
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

        $controlled = $this->controlled($first->mutants, $request, $result->evidence(), $overlap);

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
                ? $this->judges($result->mutants(), $request, $result->evidence())[5]
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
        $fitting = $this->fitting(count($survivors), $this->settings->triage()->limit());
        $left = $this->unjudged(array_slice($survivors, $fitting), OutOfTime::BeforeConfirming);
        $again = $this->again(
            Step::Survivors,
            $fitting,
            fn(): Mutants|CannotJudge => $this->adapters->runner->retry(
                $this->retimed($request)->across($request->pool()->tail()),
                Mutants::of(...array_slice($survivors, 0, $fitting)),
                $this->settings->triage()->most(),
            ),
        );

        return $again instanceof CannotJudge
            ? $again
            : new Confirmed($mutants->replacing($left), $this->killed($again));
    }

    /**
     * The mutants, each timeout, each kill a test is named for, each kill no
     * test is named for whose ending does not vouch for it, and each mutant
     * out of memory judged by its unmutated control (see TimeoutControls,
     * KillControls, UnnamedKillControls and MemoryControls), and each heavy
     * timeout by its parts after them (see PartedRuns), with the
     * evidence of each unnamed kill its control vouches for, a control
     * two of them share run once: those that fit in the time left run side
     * by side, in the request's pool, and the rest are unjudged. Under
     * `timeouts.mode: unjudged` no timeout has a control; under
     * `controls.kills: false` no kill, under `controls.memory: false` no
     * mutant out of memory, and under `timeouts.parts: false` no mutant is
     * judged by its parts (ADR-0142).
     */
    private function controlled(
        Mutants $mutants,
        MutationRequest $request,
        Evidences $evidence,
        StepsOverlap $overlap,
    ): Controlled|CannotJudge {
        [$timeouts, $kills, $memory, $unnamed, $heavy, $asked] = $this->judges($mutants, $request, $evidence);

        if ($asked === []) {
            $overlap->stop();

            return $this->partedIn(new Controlled($mutants, $evidence), $heavy, $request);
        }

        [$runs, $left] = $this->ranWith($asked, $request, $overlap);
        $fallback = $runs instanceof CannotJudge ? [] : [...$kills->fallback($runs, $left)];
        [$again, $leftAgain] = $fallback === []
            ? [ControlRuns::none(), Controls::none()]
            : $this->ranOf($fallback, $request);

        if ($runs instanceof CannotJudge || $again instanceof CannotJudge) {
            return $runs instanceof CannotJudge ? $runs : $again;
        }

        $controlled = new Controlled(
            $unnamed->applied(
                $memory->applied(
                    $kills->applied(
                        $timeouts->applied($mutants, $runs, $left),
                        $runs->and($again),
                        $left->and($leftAgain),
                    ),
                    $runs,
                    $left,
                ),
                $runs,
                $left,
            ),
            $unnamed->vouching($mutants, $evidence, $runs, $left),
        );

        return $this->partedIn($controlled, $heavy, $request);
    }

    /**
     * The controls of these mutants, as each judge asks for them: each
     * timeout's, each named kill's, each mutant's out of memory and each
     * unnamed kill's, and the heavy timeouts judged by their parts instead.
     *
     * @return array{TimeoutControls, KillControls, MemoryControls, UnnamedKillControls, HeavyMutants, list<Control>}
     */
    private function judges(Mutants $mutants, MutationRequest $request, Evidences $evidence): array
    {
        $run = $this->adapters->runner->behaviour()->selection();
        $unfiltered = $this->unfilteredIn($mutants, $evidence);
        $bounds = $request->pool()->bounding($this->settings->triage()->bounds());
        $controllable = $this->unstreamed($mutants, $evidence);
        $toggles = $this->settings->toggles();
        $timed = $this->settings->triage()->timeouts() === TimeoutMode::Unjudged ? Mutants::none() : $controllable;
        $heavy = $this->parted->among($toggles->on(Toggle::TimeoutsParts) ? $timed : Mutants::none(), $run, $bounds);
        $killed = $toggles->on(Toggle::ControlsKills) ? $controllable : Mutants::none();
        $outOfMemory = $toggles->on(Toggle::ControlsMemory) ? $controllable : Mutants::none();
        $timeouts = TimeoutControls::of(
            $heavy->without($timed),
            $unfiltered,
            $this->map,
            $this->held,
            $run,
            $bounds->perTest(),
        );
        $controls = $bounds->upToInstead($this->settings->triage()->controlMost());
        $kills = KillControls::of(
            $killed,
            $evidence,
            $this->map,
            $this->held,
            $run,
            $controls,
            $this->settings->triage()->flaky()->killControls(),
            $this->settings->effective()->pest()->longFilter(),
        );
        $memory = MemoryControls::of($outOfMemory, $unfiltered, $this->map, $this->held, $run, $bounds);
        $unnamed = UnnamedKillControls::of($killed, $evidence, $this->map, $this->held, $run, $controls);
        $asked = [...Controls::of(...[
            ...$timeouts->asked(),
            ...$kills->asked(),
            ...$unnamed->asked(),
            ...$memory->asked(),
        ])];

        return [$timeouts, $kills, $memory, $unnamed, $heavy, $asked];
    }

    /**
     * What these controls found: those started beside the survivors' runs
     * again taken, and the rest run now, as {@see ranOf()} runs them.
     *
     * @param  non-empty-list<Control>                  $asked
     * @return array{ControlRuns|CannotJudge, Controls}
     */
    private function ranWith(array $asked, MutationRequest $request, StepsOverlap $overlap): array
    {
        $started = $overlap->taken($this->stopwatch);

        if (! $started instanceof ControlRuns) {
            return $started instanceof CannotJudge ? [$started, Controls::none()] : $this->ranOf($asked, $request);
        }

        $rest = array_values(array_filter($asked, static fn(Control $control): bool => ! $started->holds($control)));

        if ($rest === []) {
            return [$started, Controls::none()];
        }

        [$runs, $left] = $this->ranOf($rest, $request);

        return [$runs instanceof CannotJudge ? $runs : $started->and($runs), $left];
    }

    /** The mutants no split stream judged: a stream's verdicts need no control (ADR-0117). */
    private function unstreamed(Mutants $mutants, Evidences $evidence): Mutants
    {
        $controllable = [];

        foreach ($mutants as $mutant) {
            if (! $evidence->of($mutant->id())->isStreamed()) {
                $controllable[] = $mutant;
            }
        }

        return Mutants::of(...$controllable);
    }

    /** What the controls found, each heavy mutant judged by its parts; or why a part cannot be judged. */
    private function partedIn(
        Controlled $controlled,
        HeavyMutants $heavy,
        MutationRequest $request,
    ): Controlled|CannotJudge {
        $mutants = $this->parted->judged(
            $controlled->mutants,
            $heavy,
            $request->pool()->processes(),
            fn(array $asked, array $ahead): array => $this->ranOf($asked, $request, Step::HeavyParts, $ahead),
        );

        return $mutants instanceof CannotJudge ? $mutants : new Controlled($mutants, $controlled->evidence);
    }

    /** The test files each of these mutants' own runs loaded with no filter, as the evidence says. */
    private function unfilteredIn(Mutants $mutants, Evidences $evidence): UnfilteredRuns
    {
        $unfiltered = UnfilteredRuns::none();

        foreach ($mutants as $mutant) {
            $files = $evidence->of($mutant->id())->unfiltered();
            $unfiltered = $files instanceof NotGiven ? $unfiltered : $unfiltered->with($mutant->id(), $files);
        }

        return $unfiltered;
    }

    /**
     * What these controls found, those that fit in the time left run side by
     * side in the request's pool and timed as this step, and those the time
     * left had no room for. Where they all fit, those beside them that fit in
     * the time left run too, in the pool's spare room.
     *
     * @param  non-empty-list<Control>                  $asked
     * @param  list<Control>                            $beside
     * @return array{ControlRuns|CannotJudge, Controls}
     */
    private function ranOf(
        array $asked,
        MutationRequest $request,
        Step $step = Step::Controls,
        array $beside = [],
    ): array {
        $longest = max(array_map(static fn(Control $control): float => $control->limit()->seconds(), $asked));
        $fitting = $this->fitting(count($asked), Seconds::of($longest));
        $room = $fitting === count($asked) ? array_values(array_filter(
            $beside,
            fn(Control $control): bool => $this->fitting(1, $control->limit()) === 1,
        )) : [];
        $from = $this->stopwatch->now();
        $runs = $fitting === 0
            ? ControlRuns::none()
            : $this->adapters->runner->controls(
                $this->retimed($request),
                Controls::of(...array_slice($asked, 0, $fitting), ...$room),
            );

        $this->stopwatch->handled($step, $from, $fitting + count($room));

        return [$runs, Controls::of(...array_slice($asked, $fitting))];
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
