<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_values;

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
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;

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

    private const string COVERAGE_FAILED = "Pest's coverage run failed. Pest said:\n%s";

    private const string STALE_MAP = 'An earlier run left %s or its JUnit log, and the gate cannot remove them.';

    private const string NOT_FOUND_AGAIN = 'Run again alone, Pest made no mutant with this id.';

    private const string NO_PROJECT = '%s holds no project Pest can run: Pest is not installed in its %s.';

    /** The project's test files, listed once for every unit the runner is asked about. */
    private TestFiles $tests;

    /** What the runner learns once for every run it starts. */
    private Remembered $remembered;

    public function __construct(private Project $project, private Shell $shell, private Patching $patching)
    {
        $this->tests = new TestFiles($project);
        $this->remembered = new Remembered();
    }

    /**
     * Pest in the project the gate runs in, installed in this vendor
     * directory, as the `pest` runner's options configure it.
     */
    public static function fromOptions(Options $options, Path $vendor): self|Invalid
    {
        $read = PestOptions::read($options);

        if ($read instanceof Invalid) {
            return $read;
        }

        $project = Project::at(self::ROOT, $read->tests(), Workspace::root(), $vendor);

        return new self($project, new ProcessShell($project->root()), $read->patching());
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
     * Pest's plugin reads each `#[Holds]` as its test files load, and Pest's
     * limit cannot be raised. Patched, every shard opens on the canary group,
     * whose test files every key then reads; unpatched, each shard pays a full
     * opening run under coverage. It runs a mutant per core.
     */
    public function behaviour(): RunnerBehaviour
    {
        $canary = $this->patching->canary();
        $pest = RunnerBehaviour::standard()
            ->holdingAsLoaded()
            ->raisingNoLimit()
            ->runningPerCore();

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

    /** Every mutant of the requested files, where there are any to mutate: Pest's `--path` never names none. */
    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        return new MutationRun($this->project, $this->shell, $this->patching, $this->remembered, $this->groups(...))
            ->of($request);
    }

    /**
     * The mutants run again, all in one run of the invocation that made
     * them: their files with their mutators, reading the coverage it read,
     * so a patched shard opens on the canary group, not the whole suite
     * under coverage. Patched, the run makes only these mutants; unpatched,
     * every mutant of their files and mutators, and each asked for is
     * matched back by the gate's id. Pest allows each mutant its own time,
     * so no limit is laid on the run.
     */
    public function retry(MutationRequest $request, Mutants $mutants, Seconds $limit): Mutants|CannotJudge
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

        $result = new MutationRun($this->project, $this->shell, $this->patching, $this->remembered, $this->groups(...))
            ->only(...$natives)
            ->of($request->narrowedTo(Paths::of(...array_values($files)), Mutators::named(...array_values($mutators))));

        return $result instanceof CannotJudge ? $result : $this->matching($mutants, $result->mutants());
    }

    /**
     * One mutant run again on its own: its file with only its mutator, judged
     * by the tests given, with what Pest printed. Pest allows each mutant its
     * own time, so no limit is laid on the run.
     */
    public function reproduce(
        Reproducible $mutant,
        WholeSuite|Group|Filter $judgedBy,
        Seconds $limit,
        Withheld $withheld,
    ): Reproduction|CannotJudge {
        $shell = Transcribing::over($this->shell);
        $request = MutationRequest::of(Paths::of($mutant->file()), $judgedBy)
            ->onlyMutators(Mutators::named($mutant->mutator()))
            ->withholding($withheld);
        $result = new MutationRun($this->project, $shell, $this->patching, $this->remembered, $this->groups(...))
            ->of($request);

        $unmade = Reason::that(self::NOT_FOUND_AGAIN);

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
    public function rootedAt(Path $package): self|CannotJudge
    {
        $project = $this->project->in($package);

        return Invocation::installedIn($project->vendor())->isIn($project)
            ? new self($project, $this->shell->in($project->root()), $this->patching)
            : CannotJudge::because(sprintf(self::NO_PROJECT, $package->value(), $project->vendor()->value()));
    }

    /** A clean coverage run into a directory, with no earlier run's map or log left there. */
    private function measured(CoverageRun $request, string $directory): Ran|CannotJudge
    {
        $map = sprintf('%s/%s', $directory, Invocation::MAP);

        if (! $this->project->without($map, sprintf('%s/%s', $directory, Invocation::JUNIT))) {
            return CannotJudge::because(sprintf(self::STALE_MAP, $map));
        }

        $ran = $this->shell->run(Invocation::installedIn($this->project->vendor())->coverage($request, $directory));

        return $ran->succeeded() ? $ran : CannotJudge::because(sprintf(self::COVERAGE_FAILED, $ran->output()));
    }

    /** Each mutant as the run found it again, by the gate's id, or unjudged where the run made no such mutant. */
    private function matching(Mutants $mutants, Mutants $found): Mutants
    {
        $again = [];
        $matched = [];

        foreach ($found as $mutant) {
            $again[$mutant->id()->value()] = $mutant;
        }

        foreach ($mutants as $mutant) {
            $matched[] = array_key_exists($mutant->id()->value(), $again)
                ? $again[$mutant->id()->value()]
                : Interpretation::unjudged($mutant, Reason::that(self::NOT_FOUND_AGAIN));
        }

        return Mutants::of(...$matched);
    }
}
