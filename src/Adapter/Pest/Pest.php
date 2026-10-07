<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;
use function basename;
use function copy;
use function is_string;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as ComposerInstalled;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\CoverageFailure;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Port\Runner;

use function realpath;
use function sprintf;

/**
 * Pest's own mutation testing, pestphp/pest-plugin-mutate, behind the Runner
 * port (ADR-0004). Pest runs in the project's root, and its results come from
 * this package's Pest plugin.
 */
final readonly class Pest implements Runner
{
    /** Where the gate runs Pest: the project's root, which the gate runs in. */
    private const string ROOT = '.';

    /** The file Pest loads before any test, in the test directory it runs with, which the gate leaves at `tests`. */
    private const string BOOT_FILE = 'tests/Pest.php';

    private const string STALE_MAP = 'An earlier run left %s or its JUnit log, and the gate cannot remove them.';

    private const string NO_PROJECT = '%s holds no project Pest can run: Pest is not installed in its %s.';

    /** Where a run of no test finds its unchanged mutant, among the adapter's own files. */
    private const string START_UP_COPY = 'pest/start-up/%s';

    private const string NOT_COPIED = 'Pest\'s run of no test needs an unchanged copy of %s, and it could not be made.';

    private const string NOT_STARTED = "Pest's run of no test, timing a mutant's start-up, failed. Pest said:\n%s";

    /** The project's test files, listed once for every unit the runner is asked about. */
    private TestFiles $tests;

    /** What the runner learns once for every run it starts. */
    private Remembered $remembered;

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Patching $patching,
        private CapFiles $files,
        private LimitBounds $bounds,
        private Clock $clock = new WallClock(),
        private Bridges $bridges = new Bridges(),
    ) {
        $this->tests = new TestFiles($project);
        $this->remembered = new Remembered();
    }

    /**
     * Pest in the project the gate runs in, installed in this vendor
     * directory, as the `pest` runner's options configure it, writing its
     * memory cap with these files and running through these processes.
     */
    public static function fromOptions(
        Options $options,
        Path $vendor,
        CapFiles $files,
        Processes $processes,
    ): self|Invalid {
        $read = PestOptions::read($options);

        if ($read instanceof Invalid) {
            return $read;
        }

        $project = Project::at(self::ROOT, $read->tests(), Workspace::root(), $vendor);

        return new self(
            $project,
            new ProcessShell($processes, $project->root()),
            $read->patching(),
            $files,
            $read->bounds(),
            bridges: $read->bridges(),
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
     * reads; unpatched, each shard pays a full opening run under coverage. It
     * runs a mutant per core, and its tests are Pest's closures.
     */
    public function behaviour(): RunnerBehaviour
    {
        $canary = $this->patching->canary();
        $pest = RunnerBehaviour::standard()
            ->holdingAsLoaded()
            ->runningPerCore()
            ->writingTestsIn(AssertionStyle::Pest);

        return $canary instanceof Group ? $pest->readingInEveryKey($canary) : $pest->openingEachShard();
    }

    /** The groups the suite lists, listed once for each set of variables withheld. */
    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        $listing = fn(): Groups|CannotJudge => Listing::groupsIn(
            $this->shell->run(Invocation::installedIn($this->project->vendor())->listingGroups($withheld)),
        );

        return $this->remembered->groups($withheld, $listing);
    }

    /**
     * A per-test line map: this job's own run of the suite under coverage, or
     * the map of the gate's own another job handed over, and never a
     * runner's map another job wrote.
     */
    public function coverage(CoverageRun|CoverageRead $request): CoverageMap|CannotJudge
    {
        if ($request instanceof CoverageRead) {
            return SharedCoverage::in($this->project, $request->directory());
        }

        $directory = $this->project->directory($request->directory());
        $ran = $this->measured($request, $directory);
        $file = $ran instanceof CannotJudge ? $ran : CoverageFile::at(sprintf('%s/%s', $directory, Invocation::MAP));

        return $file instanceof CannotJudge ? $file : $file->map($this->project);
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
        $selection = Selection::of($map->testsCoveringFile($file));

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
        $original = realpath($this->project->absolute($file));
        $copy = $original === false
            ? CannotJudge::because(sprintf(self::NOT_COPIED, $file->value()))
            : $this->unchanged($original);

        if ($copy instanceof CannotJudge) {
            return $copy;
        }

        $ran = $this->shell->run(
            Invocation::installedIn($this->project->vendor())->startingUp($withheld, $original, $copy),
        );

        return $ran->succeeded() ? $ran->timed() : CannotJudge::because(sprintf(self::NOT_STARTED, $ran->output()));
    }

    /** Every mutant of the requested files, where there are any to mutate: Pest's `--path` never names none. */
    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        return $this->run($this->shell)->of($request);
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
            $files[$mutant->location()->file()->value()] = $mutant->location()->file();
            $mutators[$mutant->mutation()->mutator()] = $mutant->mutation()->mutator();
            $natives[] = $mutant->nativeId();
        }

        if ($files === []) {
            return Mutants::none();
        }

        $result = $this->run($this->shell)
            ->upTo($most)
            ->only(...$natives)
            ->of($request->narrowedTo(
                Paths::of(...array_values($files)),
                $request->narrowing()->toMutators(Mutators::named(...array_values($mutators))),
            ));

        return $result instanceof CannotJudge
            ? $result
            : FoundAgain::among($mutants, $result->mutants(), Reason::that(FoundAgain::NOT_FOUND_AGAIN));
    }

    /**
     * A mutant as a static analyser checks it: Pest prints each mutant whole,
     * so its text is its diff put onto the original printed as Pest prints
     * it, which it is judged against (ADR-0020, decision 9).
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
        $result = $this->run($shell)
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
        return Names::listed($this->project, $this->shell, $withheld, $tests);
    }

    /** Pest in a package's directory, where Composer installed it in the package's vendor directory. */
    public function rootedAt(Path $package, Paths $tests): self|CannotJudge
    {
        $project = $this->project->in($package, $tests);

        return Invocation::installedIn($project->vendor())->isIn($project)
            ? new self(
                $project,
                $this->shell->in($project->root()),
                $this->patching,
                $this->files,
                $this->bounds,
                $this->clock,
                $this->bridges,
            )
            : CannotJudge::because(sprintf(self::NO_PROJECT, $package->value(), $project->vendor()->value()));
    }

    /**
     * An unchanged copy of a file among the adapter's own files, which a run
     * of no test serves in its place, copied as the adapter writes its other
     * files.
     */
    private function unchanged(string $original): string|CannotJudge
    {
        $copy = $this->project->fresh(sprintf(self::START_UP_COPY, basename($original)));

        if (is_string($copy)) {
            copy($original, $copy);
        }

        return $copy;
    }

    /** A clean coverage run into a directory, with no earlier run's map or log left there. */
    private function measured(CoverageRun $request, string $directory): Ran|CannotJudge
    {
        $map = sprintf('%s/%s', $directory, Invocation::MAP);

        if (! $this->project->without($map, sprintf('%s/%s', $directory, Invocation::JUNIT))) {
            return CannotJudge::because(sprintf(self::STALE_MAP, $map));
        }

        $ran = $this->shell->run(Invocation::installedIn($this->project->vendor())
            ->coverage($request, $directory, PcovReach::under($this->project->root(), $this->project->vendor())));

        return $ran->succeeded() ? $ran : CoverageFailure::said(Program::Pest, $ran->output());
    }

    /** A mutation run of this project, through this shell. */
    private function run(Shell $shell): MutationRun
    {
        return new MutationRun(
            $this->project,
            $shell,
            $this->files,
            $this->patching,
            $this->remembered,
            $this->groups(...),
            $this->bounds,
            clock: $this->clock,
            bridges: $this->bridges,
        );
    }
}
