<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use Closure;

use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\DiffPatch;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\Native\TestLayout;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Port\Runner;

use function sprintf;

/**
 * The gate's own runner of a test framework behind the Runner port
 * (ADR-0023, ADR-0027): the gate makes each mutant with its own engine, and
 * the framework runs the tests that cover it, a mutant per core at once, each
 * in a process of its own, served through the gate's override and recorded
 * by the framework's recorder, so the gate is installed in the project's
 * vendor directory. It runs in the project's root, and allows each mutant's
 * run the standard mutant limit within `timeouts.seconds` and `timeouts.most`
 * (ADR-0008).
 */
final readonly class NativeRunner implements Runner
{
    /** Where the gate runs the framework: the project's root, which the gate runs in. */
    private const string ROOT = '.';

    /** The file of its own the framework lists the suite's tests into. */
    private const string LISTING = 'listing';

    private const string NO_PROJECT
        = '%s holds no project the %s runner can run: %s is not installed in its %s.';

    private const string UNREAD = 'The gate cannot read %s, the file the mutant was made from, to check it.';

    private const string NOT_STARTED
        = "%1\$s's run of no test, timing a mutant's start-up, failed. %1\$s said:\n%2\$s";

    /** The map the last mutation run read, which a run again after it reads too. */
    private HeldCoverage $held;

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Framework $framework,
        private Engine|CannotJudge $engine,
        private LimitBounds $bounds,
        private CapFiles $files,
        private Clock $clock = new WallClock(),
    ) {
        $this->held = new HeldCoverage();
    }

    /**
     * The framework in the project the gate runs in, installed in this vendor
     * directory, as the options the flows write configure it (see
     * NativeOptions), writing its memory cap with these files and running
     * through these processes.
     *
     * @param Closure(string, Path, TestLayout): Framework $framework the framework in a root, installed in this vendor
     *                                                               directory, its tests laid out so
     */
    public static function fromOptions(
        Options $options,
        BuiltinRunner $runner,
        Closure $framework,
        Path $vendor,
        CapFiles $files,
        Processes $processes,
    ): self|Invalid {
        $read = NativeOptions::read($options, $runner);

        if ($read instanceof Invalid) {
            return $read;
        }

        $project = Project::at(self::ROOT, $read->tests(), $vendor, Workspace::root(), $runner);
        $shell = new ProcessShell($processes, $project->root(), getenv());

        return new self(
            $project,
            $shell,
            $framework(self::ROOT, $vendor, new TestFiles($project)),
            $read->engine(),
            $read->bounds(),
            $files,
        );
    }

    /**
     * The packages the framework drives, at a version the runner supports,
     * and the PHP as a mutant's run starts it, with opcache off.
     */
    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        $versions = $this->framework->versions();
        $platform = $versions instanceof CannotJudge
            ? $versions
            : Platform::ofRunner($this->shell->run($this->invocation()->describing($withheld))->output());

        return $platform instanceof Platform
            ? Identity::of($this->framework->runner()->value, $versions, $platform->digest())
            : $platform;
    }

    /**
     * `#[Holds]` is read as its framework reads it, the gate lays each
     * mutant's limit itself and can raise it, and each shard reads the map
     * the plan handed on. It stops each mutant at its first failing test,
     * runs a mutant per core at once (ADR-0023), and its tests are in the
     * style its framework's are.
     */
    public function behaviour(): RunnerBehaviour
    {
        return RunnerBehaviour::standard()
            ->runningPerCore()
            ->writingTestsIn($this->framework->testStyle())
            ->readingHolds($this->framework->holds());
    }

    /** These suites' tests and their groups, as the framework lists them into a file; or why it lists none. */
    public function listing(Withheld $withheld, Suites $suites): TestListing|CannotJudge
    {
        $file = $this->project->fresh(self::LISTING);

        return $file instanceof CannotJudge
            ? $file
            : $this->framework->listing($this->shell, $withheld, $suites, $file);
    }

    public function coverage(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge
    {
        return new Coverage($this->project, $this->shell, $this->invocation(), $this->framework)->of($request);
    }

    /** The map's tests that these test files hold, by the framework's own rules. */
    public function testsIn(Paths $files, CoverageMap $map): TestIds
    {
        return $this->framework->tests()->testsIn($files, $map);
    }

    /** The files a mutant's run selects where it cannot select a test by its id, and that name each test. */
    public function judges(Path $file, CoverageMap $map): Paths
    {
        return $this->framework->tests()->judges($file, $map);
    }

    /**
     * A run of no test, timed from its start to its end, started as a
     * mutant's own run of this file is, its mutant the file unchanged.
     */
    public function startUp(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        $override = Override::writtenFor($this->project, OwnArchive::running());
        $selection = $this->framework->selection(TestIds::none());
        $files = match (true) {
            ! is_string($override) => $override,
            ! $selection instanceof Selection => CannotJudge::because($selection->text()),
            default => MutantFiles::startingUp($this->project, $file, $selection),
        };
        $ran = match (true) {
            ! is_string($override) => $override,
            ! $files instanceof MutantFiles => $files,
            default => $this->shell->run(
                new Invocation($this->project, $override, $this->framework)->startingUp($files, $withheld),
            ),
        };

        return match (true) {
            $ran instanceof CannotJudge => $ran,
            $ran->succeeded() => $ran->timed(),
            default => CannotJudge::because(
                sprintf(self::NOT_STARTED, $this->framework->runner()->program()->title(), $ran->output()),
            ),
        };
    }

    /** Every mutant of the requested files, each run allowed its limit within `timeouts.seconds` and `.most`. */
    public function mutate(
        MutationRequest $request,
        PreChecker $preChecker = new NoPreCheck(),
    ): MutationResult|CannotJudge {
        $this->held->forget();

        $bounds = $request->pool()->bounding($this->bounds);

        return $this->mutating($this->shell)->result($request, $bounds, NotGiven::value(), $preChecker);
    }

    /**
     * What each unmutated control finds, its file served through the
     * override as a mutant's is (see UnmutatedRuns).
     */
    public function controls(MutationRequest $request, Controls $controls): ControlRuns|CannotJudge
    {
        return new UnmutatedRuns($this->project, $this->shell, $this->framework, $this->files)->of($request, $controls);
    }

    /**
     * The mutants run again, as the invocation that made them asked: over
     * their files with their mutators, reading the map it read, making only
     * them, each allowed its limit up to this most.
     */
    public function retry(MutationRequest $request, Mutants $mutants, Seconds $most): Mutants|CannotJudge
    {
        return $this->mutating($this->shell)->again(
            $request,
            $mutants,
            $request->pool()->bounding($this->bounds)->upToInstead($most),
        );
    }

    /**
     * The mutant as the engine made it: its diff put back onto the file as
     * the project holds it, where the engine changed only what the mutation
     * changed.
     */
    public function checkable(Mutant $mutant): Checkable|CannotJudge
    {
        $file = $mutant->location()->file();
        $absolute = $this->project->absolute($file);
        $written = is_file($absolute) ? file_get_contents($absolute) : false;

        if ($written === false) {
            return CannotJudge::because(sprintf(self::UNREAD, $file->value()));
        }

        $patched = DiffPatch::of($mutant->mutation())->onto(Contents::of($written), $mutant->location());

        return $patched instanceof Contents ? Checkable::inPlace($patched) : $patched;
    }

    /** One mutant run again on its own, allowed its limit up to this most, with what the framework printed. */
    public function reproduce(
        Reproducible $mutant,
        MutationRequest $request,
        Seconds $most,
    ): Reproduction|CannotJudge {
        $printing = Transcribing::over($this->shell);

        $bounds = $request->pool()->bounding($this->bounds)->upToInstead($most);

        return $this->mutating($printing)->reproduced($mutant, $request, $bounds, $printing);
    }

    /** The gate makes its own mutants, so the runner has no ignore marker of its own: `ignores.entries` is the one. */
    public function markers(Paths $files): Markers
    {
        return Markers::none();
    }

    public function definitions(): Paths
    {
        return $this->framework->definitions();
    }

    /** Each test as the framework names it. Nothing runs, so nothing is withheld. */
    public function names(TestIds $tests, Withheld $withheld): TestNames
    {
        return $this->framework->tests()->names($tests);
    }

    /** The framework in a package's directory, where Composer installed it in the package's vendor directory. */
    public function rootedAt(Path $package, Paths $tests): self|CannotJudge
    {
        $project = $this->project->in($package, $tests);
        $framework = $this->framework->in($package, new TestFiles($project));

        return $framework->isInstalled()
            ? new self(
                $project,
                $this->shell->in($project->root()),
                $framework,
                $this->engine,
                $this->bounds,
                $this->files,
                $this->clock,
            )
            : CannotJudge::because(sprintf(
                self::NO_PROJECT,
                $package->value(),
                $framework->runner()->value,
                $framework->runner()->program()->title(),
                $project->vendor()->value(),
            ));
    }

    /** The framework as the gate starts it, the override named where a mutant's run writes it. */
    private function invocation(): Invocation
    {
        return new Invocation($this->project, Override::pathIn($this->project), $this->framework);
    }

    /** A run of the gate's own mutants through this shell. */
    private function mutating(Shell $shell): Mutating
    {
        return new Mutating(
            $this->project,
            $shell,
            $this->framework,
            $this->files,
            $this->held,
            $this->engine,
            $this->clock,
        );
    }
}
