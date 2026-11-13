<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Adapter\Console\ConsoleReport;
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
use NightWorksIO\MutationGate\Core\Runner\NoProcessLog;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;

use function sprintf;

/**
 * The heavy mutants of an invocation judged in parts (see HeavyMutants):
 * each served as its runner gives it to a static analyser, its parts run
 * one round after another, every heavy mutant's next run side by side in a
 * round and, under `timeouts.heavyFill`, their parts ahead filling the pool,
 * until each is judged, each round naming in the log what it runs, so a slow
 * phase can be read even from the log of a job that was cancelled. The
 * file each test is in is read from the suite only where a mutant is heavy.
 *
 * @internal
 */
final readonly class PartedRuns
{
    /** A line of the log, naming what each round of a heavy mutant's parts runs. */
    private const string SAID = ConsoleReport::JUDGED;

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private CoverageMap $map,
        private HeldCovered $held,
        private ProcessLog $log = new NoProcessLog(),
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
        )->judgedByAtMost($this->settings->triage()->heavy()->parts());
    }

    /**
     * These mutants, each heavy one judged by its parts, round after round in
     * a pool this wide; or why a round cannot judge.
     *
     * @param Closure(non-empty-list<Control>, list<Control>): array{ControlRuns|CannotJudge, Controls} $ran what each
     *     run of a round, those waited on and those ahead, found, and those the time left had no room for
     */
    public function judged(Mutants $mutants, HeavyMutants $heavy, ProcessCount $pool, Closure $ran): Mutants|CannotJudge
    {
        $slots = $this->settings->triage()->heavy()->fills() ? $pool : ProcessCount::single();
        $asked = [...$heavy->asked()];

        while ($asked !== []) {
            foreach ($heavy->progress($slots) as $progress) {
                $this->log->say(sprintf(self::SAID, $progress));
            }

            [$runs, $left] = $ran($asked, $heavy->ahead($slots));

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
