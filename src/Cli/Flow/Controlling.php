<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function max;

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
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\UnfilteredRuns;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The controls of one runner invocation (ADR-0008): each timeout, each kill a
 * test is named for, each kill no test is named for whose ending does not
 * vouch for it, and each mutant out of memory judged by its tests run
 * unmutated, those that fit in the time left side by side, and each heavy
 * timeout by its parts after them (see PartedRuns). A split stream's
 * verdicts need no control (ADR-0117).
 *
 * @internal
 */
final readonly class Controlling
{
    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private TimeLeft $timeLeft,
        private Stopwatch $stopwatch,
        private CoverageMap $map,
        private HeldCovered $held,
        private PartedRuns $parted,
    ) {
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
    public function controlled(
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
     * The controls these mutants ask for, before their survivors are screened.
     *
     * @return list<Control>
     */
    public function asked(Mutants $mutants, MutationRequest $request, Evidences $evidence): array
    {
        return $this->judges($mutants, $request, $evidence)[5];
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
        $bounds = $request->pool()->bounding($this->settings->triage()->limits()->bounds());
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
        $controls = $bounds->upToInstead($this->settings->triage()->limits()->controlMost());
        $kills = KillControls::of(
            $killed,
            $evidence,
            $this->map,
            $this->held,
            $run,
            $controls,
            $this->settings->triage()->flaky()->killControls(),
            $this->settings->effective()->running()->pest()->longFilter(),
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
        $fitting = $this->timeLeft->fitting(count($asked), Seconds::of($longest));
        $room = $fitting === count($asked) ? array_values(array_filter(
            $beside,
            fn(Control $control): bool => $this->timeLeft->fitting(1, $control->limit()) === 1,
        )) : [];
        $from = $this->stopwatch->now();
        $runs = $fitting === 0
            ? ControlRuns::none()
            : $this->adapters->runner->controls(
                $this->timeLeft->retimed($request),
                Controls::of(...array_slice($asked, 0, $fitting), ...$room),
            );

        $this->stopwatch->handled($step, $from, $fitting + count($room));

        return [$runs, Controls::of(...array_slice($asked, $fitting))];
    }
}
