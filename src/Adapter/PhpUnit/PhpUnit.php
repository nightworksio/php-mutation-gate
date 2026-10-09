<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;
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
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitGroups;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Port\Runner;

use function sprintf;

/**
 * The gate's own PHPUnit runner behind the Runner port (ADR-0023, decisions 8
 * to 10): the gate makes each mutant with its own engine, and PHPUnit runs
 * the tests that cover it, a mutant per core at once, each in a process of
 * its own, served through the gate's override and recorded by its extension, so
 * the gate is installed in the project's vendor directory. It runs in the
 * project's root, and allows each mutant's run the standard mutant limit
 * within `timeouts.seconds` and `timeouts.most` (ADR-0008, decision 2).
 */
final readonly class PhpUnit implements Runner
{
    /** Where the gate runs PHPUnit: the project's root, which the gate runs in. */
    private const string ROOT = '.';

    private const string NO_PROJECT
        = '%s holds no project the phpunit runner can run: PHPUnit is not installed in its %s.';

    private const string UNREAD = 'The gate cannot read %s, the file the mutant was made from, to check it.';

    private const string NOT_STARTED
        = "PHPUnit's run of no test, timing a mutant's start-up, failed. PHPUnit said:\n%s";

    /** The project's test files, walked once for every unit the runner is asked about. */
    private TestFiles $tests;

    /** The map the last mutation run read, which a run again after it reads too. */
    private HeldCoverage $held;

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Engine|CannotJudge $engine,
        private LimitBounds $bounds,
        private CapFiles $files,
        private Clock $clock = new WallClock(),
    ) {
        $this->tests = new TestFiles($project);
        $this->held = new HeldCoverage();
    }

    /**
     * PHPUnit in the project the gate runs in, installed in this vendor
     * directory, as the options the flows write configure it (see
     * PhpUnitOptions), writing its memory cap with these files and running
     * through these processes.
     */
    public static function fromOptions(
        Options $options,
        Path $vendor,
        CapFiles $files,
        Processes $processes,
    ): self|Invalid {
        $read = PhpUnitOptions::read($options);

        if ($read instanceof Invalid) {
            return $read;
        }

        $project = Project::at(self::ROOT, $read->tests(), $vendor, Workspace::root());
        $shell = new ProcessShell($processes, $project->root(), getenv());

        return new self($project, $shell, $read->engine(), $read->bounds(), $files);
    }

    /**
     * PHPUnit and php-code-coverage, at a version the runner supports, and
     * the PHP as a mutant's run starts it, with opcache off.
     */
    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        $versions = Installed::versionsIn($this->project->installed());
        $platform = $versions instanceof CannotJudge
            ? $versions
            : Platform::ofRunner($this->shell->run($this->invocation()->describing($withheld))->output());

        return $platform instanceof Platform
            ? Identity::of(BuiltinRunner::PhpUnit->value, $versions, $platform->digest())
            : $platform;
    }

    /**
     * PHPUnit lists `#[Holds]` as groups, the gate lays each mutant's limit
     * itself and can raise it, and each shard reads the map the plan handed
     * on. It stops each mutant at its first failing test, and runs a mutant
     * per core at once (ADR-0023, decisions 5 and 12).
     */
    public function behaviour(): RunnerBehaviour
    {
        return RunnerBehaviour::standard()->runningPerCore();
    }

    /** The groups PHPUnit lists for the suite. */
    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        return PhpUnitGroups::listedIn($this->shell->run($this->invocation()->listingGroups($withheld)));
    }

    public function coverage(CoverageRun|CoverageRead $request): CoverageMap|CannotJudge
    {
        return new Coverage($this->project, $this->shell, $this->invocation())->of($request);
    }

    /** The map's tests whose classes these test files declare, or whose files are gone. */
    public function testsIn(Paths $files, CoverageMap $map): TestIds
    {
        return $this->tests->holding($files, $map);
    }

    /**
     * The files that declare the classes of the tests that cover the file:
     * the files a mutant's run selects where it cannot select a test by its
     * id, and that name each test.
     */
    public function judges(Path $file, CoverageMap $map): Paths
    {
        $covering = $map->testsCoveringFile($file);

        return count($covering) === 0 ? Paths::none() : $this->tests->declaring($covering)->files();
    }

    /**
     * A run of no test, timed from its start to its end, started as a
     * mutant's own run of this file is, its mutant the file unchanged.
     */
    public function startUp(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        $override = Override::writtenFor($this->project);
        $files = is_string($override) ? MutantFiles::startingUp($this->project, $file) : $override;
        $ran = match (true) {
            ! is_string($override) => $override,
            ! $files instanceof MutantFiles => $files,
            default => $this->shell->run(new Invocation($this->project, $override)->startingUp($files, $withheld)),
        };

        return match (true) {
            $ran instanceof CannotJudge => $ran,
            $ran->succeeded() => $ran->timed(),
            default => CannotJudge::because(sprintf(self::NOT_STARTED, $ran->output())),
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
        return new UnmutatedRuns($this->project, $this->shell, $this->tests, $this->files)->of($request, $controls);
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

    /** One mutant run again on its own, allowed its limit up to this most, with what PHPUnit printed. */
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

    /** The PHPUnit config in the project's root, whichever name it has. */
    public function definitions(): Paths
    {
        return PhpUnitConfig::candidatesIn(Path::root());
    }

    /**
     * Each test by the file that declares its class and its method's name.
     * Nothing runs, so nothing is withheld.
     */
    public function names(TestIds $tests, Withheld $withheld): TestNames
    {
        return $this->tests->declaring($tests)->names($tests);
    }

    /** PHPUnit in a package's directory, where Composer installed it in the package's vendor directory. */
    public function rootedAt(Path $package, Paths $tests): self|CannotJudge
    {
        $project = $this->project->in($package, $tests);

        return $project->hasPhpUnit()
            ? new self(
                $project,
                $this->shell->in($project->root()),
                $this->engine,
                $this->bounds,
                $this->files,
                $this->clock,
            )
            : CannotJudge::because(sprintf(self::NO_PROJECT, $package->value(), $project->vendor()->value()));
    }

    /** PHPUnit as the gate starts it, the override named where a mutant's run writes it. */
    private function invocation(): Invocation
    {
        return new Invocation($this->project, Override::pathIn($this->project));
    }

    /** A run of the gate's own mutants through this shell. */
    private function mutating(Shell $shell): Mutating
    {
        return new Mutating(
            $this->project,
            $shell,
            $this->tests,
            $this->files,
            $this->held,
            $this->engine,
            $this->clock,
        );
    }
}
