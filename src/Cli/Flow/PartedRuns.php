<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\HeavyMutants;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;

/**
 * The heavy mutants of an invocation judged in parts (see HeavyMutants):
 * each served as its runner gives it to a static analyser, its parts run
 * one round after another, every heavy mutant's next run side by side in a
 * round, until each is judged. The file each test is in is read from the
 * suite only where a mutant is heavy.
 */
final readonly class PartedRuns
{
    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private CoverageMap $map,
        private HeldCovered $held,
    ) {
    }

    /** The heavy mutants among these, with their parts. */
    public function among(Mutants $mutants, RunSelection $run, LimitBounds $bounds): HeavyMutants
    {
        return HeavyMutants::of(
            $mutants,
            $this->map,
            $this->held,
            $run,
            $bounds,
            $this->served(...),
            $this->places(...),
        );
    }

    /**
     * These mutants, each heavy one judged by its parts, round after round;
     * or why a round cannot judge.
     *
     * @param Closure(non-empty-list<Control>): array{ControlRuns|CannotJudge, Controls} $ran what each run of a
     *                                                                                        round found, and those
     *                                                                                        the time left had no
     *                                                                                        room for
     */
    public function judged(Mutants $mutants, HeavyMutants $heavy, Closure $ran): Mutants|CannotJudge
    {
        $asked = [...$heavy->asked()];

        while ($asked !== []) {
            [$runs, $left] = $ran($asked);

            if ($runs instanceof CannotJudge) {
                return $runs;
            }

            $heavy = $heavy->after($runs, $left);
            $asked = [...$heavy->asked()];
        }

        return $heavy->applied($mutants);
    }

    /** The text a mutant is served as, as its runner gives it to a static analyser; or why it cannot be. */
    private function served(Mutant $mutant): Contents|CannotJudge
    {
        $checkable = $this->adapters->runner->checkable($mutant);

        return $checkable instanceof Checkable ? $checkable->mutant() : $checkable;
    }

    /** The file each test of the map is in, as the runner places it; none where the suite cannot be read. */
    private function places(): TestPlaces
    {
        $inventory = Inventory::of($this->adapters, $this->settings);

        return $inventory instanceof Inventory
            ? TestsOfTheSuite::placed($this->adapters, $inventory->suite, $this->map)
            : TestPlaces::none();
    }
}
