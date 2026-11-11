<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;

use Closure;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\TimedTests;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as ComposerInstalled;
use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\ControlsSideBySide;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LeavesPhpReport;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\ListsSideBySide;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PendingControls;
use NightWorksIO\MutationGate\Core\Runner\PendingListings;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunExtension;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\MapEngine;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Port\Runner;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

use function sprintf;

/**
 * Pest's own mutation testing, pestphp/pest-plugin-mutate, behind the Runner
 * port (ADR-0004). Pest runs in the project's root, and its results come from
 * this package's Pest plugin.
 *
 * @internal
 */
final readonly class Pest implements ControlsSideBySide, LeavesPhpReport, ListsSideBySide, Runner
{
    /** Where the gate runs Pest: the project's root, which the gate runs in. */
    private const string ROOT = '.';

    /** The file Pest loads before any test, in the test directory it runs with, which the gate leaves at `tests`. */
    private const string BOOT_FILE = 'tests/Pest.php';

    private const string NO_PROJECT = '%s holds no project Pest can run: Pest is not installed in its %s.';

    /** The project's test files, listed once for every unit the runner is asked about. */
    private TestFiles $tests;

    /** What the runner learns once for every run it starts. */
    private Remembered $remembered;

    /** The tests of the map last asked about, counted once for every file the runner is asked about. */
    private MapSuites $suites;

    /** The project's listings and mutation runs, through its shell. */
    private PestRuns $runs;

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Patching $patching,
        private CapFiles $files,
        private PackageFiles $packages,
        private LimitBounds $bounds,
        private Clock $clock = new WallClock(),
        private Bridges $bridges = new Bridges(),
        MapEngine|NotGiven $maps = new NotGiven(),
        RunExtension|NotGiven $extension = new NotGiven(),
    ) {
        $this->tests = new TestFiles($project);
        $this->remembered = new Remembered($maps, $patching->toggled(Toggle::PestRemember));
        $this->suites = new MapSuites();
        $this->runs = new PestRuns(
            $project,
            $shell,
            $files,
            $packages,
            $patching,
            $this->remembered,
            $bounds,
            $clock,
            $bridges,
            $extension,
        );
    }

    /**
     * Pest in the project the gate runs in, installed in this vendor
     * directory, as the `pest` runner's options configure it, writing its
     * memory cap with these files, reading whether pest:patch is applied
     * through these package files and running through these processes, with
     * the engine that vouches for each map handed over, where one does
     * (ADR-0118). Under the gate's PHAR, every PHP process of a run loads the
     * archive first, and its plugin, this class, in the place of a copy of the
     * gate's the project's Pest lists (ADR-0140). A preloaded mutant is
     * served in a fresh run where this places it in the project (ADR-0148).
     *
     * @param class-string                                               $plugin
     * @param Closure(string, Paths, Path, Path): MutantPlacement|NotGiven $placing
     */
    public static function fromOptions(
        Options $options,
        Path $vendor,
        CapFiles $files,
        PackageFiles $packages,
        Processes $processes,
        OwnArchive|NotGiven $archive,
        string $plugin,
        MapEngine|NotGiven $maps = new NotGiven(),
        RunExtension|NotGiven $extension = new NotGiven(),
        Closure|NotGiven $placing = new NotGiven(),
    ): self|Invalid {
        $read = PestOptions::read($options);

        if ($read instanceof Invalid) {
            return $read;
        }

        $project = Project::at(self::ROOT, $read->tests(), Workspace::root(), $vendor, $placing);
        $loading = $archive instanceof OwnArchive
            ? ArchiveLoading::writtenFor($project, $archive, GatePlugins::listedIn($project, $packages), $plugin)
            : NotGiven::value();

        return $loading instanceof CannotJudge ? Invalid::because(Problem::at('', $loading->why())) : new self(
            $project,
            new ProcessShell($processes, $project->root(), $loading)
                ->longestFirst($read->patching()->toggled(Toggle::PestLongestFirst)),
            $read->patching(),
            $files,
            $packages,
            $read->bounds(),
            bridges: $read->bridges(),
            maps: $maps,
            extension: $extension,
        );
    }

    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        $versions = Installed::versionsIn(
            $this->project->absolute(ComposerInstalled::fileIn($this->project->vendor())),
        );
        $platform = $versions instanceof CannotJudge ? $versions : $this->remembered->platform(
            $withheld,
            fn(): Platform|CannotJudge => Platform::ofRunner(
                $this->shell->run(Command::php($withheld, ...Platform::describing()))->output(),
            ),
        );

        return $platform instanceof Platform
            ? Identity::of(BuiltinRunner::Pest->value, $versions, $platform->digest())
            : $platform;
    }

    /**
     * Pest's plugin reads each `#[Holds]` as its test files load. Patched,
     * every shard opens on the canary group, whose test files every key then
     * reads, and a mutant's run selects exactly its covering rows; unpatched,
     * each shard pays a full opening run under coverage, and a mutant's run
     * selects every row of its covering tests' data sets. It runs a mutant
     * per core, and its tests are Pest's closures.
     */
    public function behaviour(): RunnerBehaviour
    {
        $canary = $this->patching->canary();
        $pest = RunnerBehaviour::standard()
            ->selecting($this->patching->selection($this->tests))
            ->holdingAsLoaded()
            ->runningPerCore()
            ->writingTestsIn(AssertionStyle::Pest);

        return $canary instanceof Group ? $pest->readingInEveryKey($canary) : $pest->openingEachShard();
    }

    /** These suites' tests and their groups, listed once for each set of suites and variables withheld. */
    public function listing(Withheld $withheld, Suites $suites): TestListing|CannotJudge
    {
        return $this->runs->listing($withheld, $suites);
    }

    public function listingsStarted(Withheld $withheld, Suites ...$suites): PendingListings
    {
        return $this->runs->listingsStarted($withheld, ...$suites);
    }

    /**
     * A per-test line map: this job's own run of the suite under coverage,
     * the report a run the project started in this job left, or the map of
     * the gate's own another job handed over, and never a runner's map
     * another job wrote.
     */
    public function coverage(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge
    {
        if ($request instanceof CoverageRead) {
            return SharedCoverage::in($this->project, $request->directory(), $this->remembered->vouching());
        }

        $directory = $request instanceof CoverageRun
            ? $this->project->directory($request->directory())
            : $this->project->absolute($request->directory());
        $ran = $request instanceof CoverageRun
            ? new CoverageRunning($this->project, $this->shell)->into($request, $directory)
            : $request;
        $file = $ran instanceof CannotJudge ? $ran : CoverageFile::at(sprintf('%s/%s', $directory, Invocation::MAP));

        return $file instanceof CannotJudge
            ? $file
            : TimedTests::onto($file->map($this->project), sprintf('%s/%s', $directory, Invocation::TIMED));
    }

    /**
     * The report a run the project started in this job left in a directory,
     * with the tests the run timed beside it and what they loaded.
     */
    public function report(CoverageRan $ran): RanReport
    {
        $directory = $this->project->absolute($ran->directory());

        return RanReport::at(
            sprintf('%s/%s', $directory, Invocation::MAP),
            Serializer::SERIALIZATION_FORMAT,
            TimedTests::beside(sprintf('%s/%s', $directory, Invocation::TIMED)),
            $this->project->relative(...),
        );
    }

    /** The map's tests of the classes Pest declares for these test files, or that they declare themselves. */
    public function testsIn(Paths $files, CoverageMap $map): TestIds
    {
        return $this->tests->holding($files, $map);
    }

    /**
     * Every test file whose class a covering test's `<Class>::` selects, or
     * every test file when the filter will not fit and the mutant runs against
     * the whole suite.
     */
    public function judges(Path $file, CoverageMap $map): Paths
    {
        $selection = $this->patching->selecting($map->testsCoveringFile($file), $this->suites->of($map));

        return $selection->fits() ? $this->tests->naming($selection->classes()) : $this->tests->all();
    }

    /**
     * A run of no test, timed from its start to its end, started as
     * pest-plugin-mutate starts a mutant's own run of this file, its mutant
     * an unchanged copy, so the plugin serves the file through its own
     * wrapper as it does a mutant's.
     */
    public function startUp(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        return new StartingUp($this->project, $this->shell)->of($file, $withheld);
    }

    /** Every mutant of the requested files, where there are any to mutate: Pest's `--path` never names none. */
    public function mutate(
        MutationRequest $request,
        PreChecker $preChecker = new NoPreCheck(),
    ): MutationResult|CannotJudge {
        return $this->runs->through($this->shell)->checkingWith($preChecker)->of($request);
    }

    /** What each unmutated control finds, its file served through Pest's override (see UnmutatedRuns). */
    public function controls(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        $shell = TailShell::of($this->shell, $this->project, $this->patching, $request);

        return new UnmutatedRuns(
            $this->project,
            $shell,
            $this->files,
            $this->patching->isOn() && $this->patching->longFilter() === LongFilter::Set,
        )->of($request, $controls);
    }

    /**
     * The same controls started now, their workers and places apart from the
     * survivors' runs again that start beside them, taken once the gate needs
     * them.
     */
    public function controlsStarted(MutationRequest $request, Controls $controls): PendingControls
    {
        $shell = TailShell::apart($this->shell, $this->project, $this->patching, $request);

        return new UnmutatedRuns(
            $this->project,
            $shell,
            $this->files,
            $this->patching->isOn() && $this->patching->longFilter() === LongFilter::Set,
        )->started($request, $controls);
    }

    /**
     * The mutants run again, all in one run of the invocation that made
     * them: their files with their mutators, reading the coverage it read,
     * so a patched shard opens on the canary group, not the whole suite
     * under coverage. Patched, the run makes only these mutants; unpatched,
     * every mutant of their files and mutators, and each asked for is
     * matched back by Pest's id and handed back under the gate's. Patched,
     * each mutant is allowed its limit up to this most; unpatched, Pest's own.
     */
    public function retry(MutationRequest $request, Mutants $mutants, Seconds $most): Mutants|CannotJudge
    {
        $files = [];
        $mutators = [];
        $natives = [];

        foreach ($mutants as $mutant) {
            $files[] = $mutant->location()->file();
            $mutators[$mutant->mutation()->mutator()] = $mutant->mutation()->mutator();
            $natives[] = $mutant->nativeId();
        }

        if ($files === []) {
            return Mutants::none();
        }

        $result = $this->runs->through($this->shell)
            ->upTo($most)
            ->only(...$natives)
            ->of($request->narrowedTo(
                Paths::of(...$files),
                $request->narrowing()->toMutators(Mutators::named(...array_values($mutators))),
            ));

        return $result instanceof CannotJudge
            ? $result
            : FoundAgain::among($mutants, $result->mutants(), Reason::that(FoundAgain::NOT_FOUND_AGAIN));
    }

    /**
     * A mutant as a static analyser checks it: Pest prints each mutant whole,
     * so its text is its diff put onto the original printed as Pest prints
     * it, which it is judged against (ADR-0020).
     */
    public function checkable(Mutant $mutant): Checkable|CannotJudge
    {
        return Printed::checkable($this->project, $mutant);
    }

    /**
     * One mutant run again on its own: the request narrowed to its file with
     * only its mutator, with what Pest printed. Patched, it is allowed its
     * limit up to this most; unpatched, Pest's own.
     */
    public function reproduce(
        Reproducible $mutant,
        MutationRequest $request,
        Seconds $most,
    ): Reproduction|CannotJudge {
        $shell = Transcribing::over($this->shell);
        $result = $this->runs->through($shell)
            ->upTo($most)
            ->of($request->narrowedTo(
                Paths::of($mutant->file()),
                $request->narrowing()->toMutators(Mutators::named($mutant->mutator())),
            ));

        $unmade = Reason::that(FoundAgain::NOT_FOUND_AGAIN);

        return $result instanceof CannotJudge
            ? $result
            : Reproduction::among($mutant->id(), $result->mutants(), $unmade, $shell->printed());
    }

    /** Every one of Pest's own ignore markers in the PHP files these paths name. */
    public function markers(Paths $files): Markers
    {
        return NativeMarkers::in($this->project, $files);
    }

    /** `tests/Pest.php`, and the PHPUnit config in the project's root, whichever name it has. */
    public function definitions(): Paths
    {
        return Paths::of(Path::of(self::BOOT_FILE), ...PhpUnitConfig::candidatesIn(Path::root()));
    }

    /**
     * Each test by the file Pest built it from and the description Pest gives
     * it, as the plugin names them in a run that lists the tests and runs
     * none. Listing loads the project's code, which never sees the variables
     * withheld.
     */
    public function names(TestIds $tests, Withheld $withheld): TestNames|CannotJudge
    {
        $names = $this->remembered->names(
            $withheld,
            fn(): Names|CannotJudge => Names::listed($this->project, $this->shell, $withheld),
        );

        return $names instanceof CannotJudge ? $names : $names->of($this->project, $tests);
    }

    /** Pest in a package's directory, where Composer installed it in the package's vendor directory. */
    public function rootedAt(Path $package, Paths $tests): self|CannotJudge
    {
        $project = $this->project->in($package, $tests);

        return Invocation::of($project)->isIn($project)
            ? new self(
                $project,
                $this->shell->in($project->root()),
                $this->patching,
                $this->files,
                $this->packages,
                $this->bounds,
                $this->clock,
                $this->bridges,
            )
            : CannotJudge::because(sprintf(self::NO_PROJECT, $package->value(), $project->vendor()->value()));
    }
}
