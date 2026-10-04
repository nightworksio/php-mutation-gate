<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Remeasuring;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The coverage map `watch` keeps between its rounds (ADR-0010, decision 1):
 * built once at start, and after each change read again, with the entries of
 * the tests the change touched measured again and replaced (ADR-0023,
 * decision 3). It is built again where the change reaches the whole suite,
 * or the kept map cannot be read or measured again, so a run that fails
 * says why in the round that reads it.
 */
final readonly class KeptCoverage
{
    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** The whole suite's map, built afresh where a round reads it. */
    public static function built(): CoverageRun
    {
        return CoverageRun::of(WholeSuite::tests(), Workspace::coverage());
    }

    /**
     * What a round reads after these changes: the kept map, brought up to
     * date with the tests they touched, or the whole suite's built afresh.
     */
    public function after(Changes $changes): CoverageRun|CoverageRead
    {
        $inventory = Inventory::of($this->adapters, $this->settings);
        $sources = $inventory instanceof Inventory
            ? Reached::sources($changes, Revision::head(), $this->adapters, $inventory->suite->sources())
            : Sources::none();
        $again = $inventory instanceof Inventory
            ? new Remeasuring(Reached::layout($this->adapters, $this->settings, $inventory->suite), $inventory->trees)
                ->of($changes, $sources)
            : WholeSuite::tests();

        return match (true) {
            $again instanceof WholeSuite => self::built(),
            count($again->files()) === 0 => CoverageRead::from(Workspace::coverage()),
            default => $this->updated($again->files(), $sources),
        };
    }

    /**
     * The kept map with the entries of the tests these files hold measured
     * again, written where a round reads it; or the whole suite's built
     * afresh, where that cannot be done.
     */
    private function updated(Paths $files, Sources $sources): CoverageRun|CoverageRead
    {
        $read = CoverageRead::from(Workspace::coverage());
        $kept = $this->adapters->runner->coverage($read);
        $held = $kept instanceof CoverageMap ? $this->adapters->runner->testsIn($files, $kept) : $kept;
        $measured = $held instanceof TestIds ? $this->measured($files, $sources) : $held;
        $written = $kept instanceof CoverageMap && $held instanceof TestIds && $measured instanceof CoverageMap
            ? $this->adapters->project->write(
                CoverageMapFile::in(Workspace::coverage()),
                Contents::of(CoverageMapFile::encode(
                    Remeasured::over($kept, $held, $measured),
                    Measuring::now($this->adapters),
                )),
            )
            : $measured;

        return $written instanceof Written ? $read : self::built();
    }

    /** What a run of those of these files still on disk measures, or nothing where none is. */
    private function measured(Paths $files, Sources $sources): CoverageMap|CannotJudge
    {
        $present = Paths::none();

        foreach ($files as $file) {
            $present = $sources->now($file) instanceof Contents ? $present->with($file) : $present;
        }

        return count($present) === 0
            ? CoverageMap::empty()
            : $this->adapters->runner->coverage(
                $this->adapters->covering(CoverageRun::of(TestPaths::of($present), Workspace::remeasuredCoverage())),
            );
    }
}
