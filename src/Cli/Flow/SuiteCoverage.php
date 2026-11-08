<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;

use Closure;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Additions;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Role;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * What of a coverage map the suites the config lists may judge (ADR-0002,
 * and ADR-0005): the tests of a suite that judges every unit on every
 * line they run, a holding suite's tests only on the lines they run
 * inside the paths they hold, and no test of a suite neither list names.
 * A file of tests is run by its path, which PHPUnit and Pest take in
 * place of `--testsuite`, so the gate sorts the tests by their files.
 *
 * @internal
 */
final readonly class SuiteCoverage
{
    private function __construct(
        private Adapters $adapters,
        private Suite $suite,
        private Paths $testFiles,
        private Additions $additions,
    ) {
    }

    /** The coverage the inventory's suites may judge. */
    public static function of(Adapters $adapters, Inventory $inventory): self
    {
        $files = Paths::none();

        foreach ($inventory->suite->files() as $file) {
            $files = $file->role() === Role::TestCase ? $files->with($file->fingerprint()->path()) : $files;
        }

        return new self($adapters, $inventory->suite, $files, $inventory->additions);
    }

    /** A run of the whole suite under coverage: of the holding suites too, where something is held from them. */
    public function whole(CoverageRun $run): CoverageRun
    {
        $run = $this->adapters->covering($run);

        return count($this->additions) === 0
            ? $run
            : $run->amongSuites($this->adapters->narrowing->mappedSuitesFor(WholeSuite::tests()));
    }

    /** Of some test files, those whose tests may judge: a holding suite's only where something is held from it. */
    public function measured(Paths $files): TestPaths
    {
        $holding = count($this->additions) === 0 ? Paths::none() : $this->holding($files);

        return TestPaths::of($this->judging($files)->and($holding));
    }

    /**
     * A map with only what the listed suites may judge in it, once each hold
     * from the holding suites runs a line of what it holds there; or why not.
     */
    public function admitted(CoverageMap $map): CoverageMap|CannotJudge
    {
        $others = $this->testFiles->without($this->judging($this->testFiles));

        if (count($others) === 0) {
            return $map;
        }

        $leaving = $this->adapters->runner->testsIn($others, $map);
        $admitted = $leaving instanceof TestIds ? $this->kept($map, $leaving) : $leaving;
        $unrun = $admitted instanceof CoverageMap ? $this->additions->unrunIn($admitted) : $admitted;

        return $unrun instanceof CannotJudge ? $unrun : $admitted;
    }

    /**
     * A request of the whole suite whose files a hold from the holding suites
     * holds, reading the gate's map of the suite, which keeps each holding
     * test on the lines of what it holds: a run's own coverage measures only
     * the suites that judge every unit. Any other request as it is; or why
     * the map cannot be made.
     */
    public function handing(MutationRequest $request): MutationRequest|CannotJudge
    {
        if (! $request->judgedBy() instanceof WholeSuite || ! $this->holdsAny($request->files())) {
            return $request;
        }

        $map = $this->adapters->runner->coverage(
            $this->whole(CoverageRun::of(WholeSuite::tests(), Workspace::suiteCoverage())),
        );
        $admitted = $map instanceof CoverageMap ? $this->admitted($map) : $map;
        $written = $admitted instanceof CoverageMap ? $this->adapters->project->write(
            CoverageMapFile::in(Workspace::admittedCoverage()),
            Contents::of(CoverageMapFile::encode($admitted, Unplaced::map())),
        ) : $admitted;

        return $written instanceof CannotJudge
            ? $written
            : $request->reusingCoverage(Handed::maps(Workspace::admittedCoverage(), Workspace::admittedCoverage()));
    }

    /** Of some test files, those a suite whose tests judge every unit holds. */
    private function judging(Paths $files): Paths
    {
        return $this->suite->among($files, $this->adapters->narrowing->suitesFor(WholeSuite::tests()));
    }

    /** Of some test files, those a suite whose tests judge only the units they hold holds; none if none is listed. */
    private function holding(Paths $files): Paths
    {
        $suites = $this->adapters->narrowing->holdingSuites();

        return $suites instanceof Suites ? $this->suite->among($files, $suites) : Paths::none();
    }

    private function holdsAny(Paths $files): bool
    {
        return array_any([...$files], fn(Path $file): bool => $this->additions->holds($file));
    }

    /** The map, less these tests but on the lines of what one holds (see Admission). */
    private function kept(CoverageMap $map, TestIds $leaving): CoverageMap
    {
        return Admission::of($leaving, $this->staysOn(...))->applied($map);
    }

    /**
     * Whether a test leaving stays on the lines of a file: where one of the
     * holds that hold the file adds it to the file's judges.
     *
     * @return Closure(TestId): bool
     */
    private function staysOn(Path $file): Closure
    {
        $holding = $this->additions->on($file);

        return static fn(TestId $test): bool => $holding->adds($test, $file);
    }
}
