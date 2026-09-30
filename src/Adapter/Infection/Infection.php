<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_keys;
use function getenv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as ComposerInstalled;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
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
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;

use function sprintf;

/**
 * Infection, with PHPUnit, behind the Runner port (ADR-0004). It runs in the
 * project's root on a config the gate writes for each run, and always reads
 * a coverage directory the gate chose: one another job wrote for the whole
 * suite, or one the adapter writes first by running PHPUnit under coverage,
 * narrowed to the tests that judge a held path.
 */
final readonly class Infection implements Runner
{
    private const string NO_PROJECT = '%s holds no project Infection can run: Infection is not installed there.';

    private const string COVERAGE_FAILED = "PHPUnit's coverage run failed. PHPUnit said:\n%s";

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Seconds $cap,
        private bool $nativeMarkersAllowed,
        private Clock $clock = new WallClock(),
        private HeldCoverage $held = new HeldCoverage(),
    ) {
    }

    /**
     * The adapter in the project the gate runs in, from the options the flows
     * write (see Setup).
     */
    public static function fromOptions(Options $options): self|Invalid
    {
        $setup = Setup::of($options);

        if ($setup instanceof Invalid) {
            return $setup;
        }

        $project = Project::at(Root::here(), $setup->tests(), Workspace::root());

        return new self(
            $project,
            new ProcessShell($project->root(), getenv()),
            $setup->cap(),
            nativeMarkersAllowed: $setup->allowsNativeMarkers(),
        );
    }

    /**
     * Infection, PHPUnit and php-code-coverage, the static analysis tool the
     * project has kill mutants, and the PHP as Infection starts each mutant's
     * run: without `initialTestsPhpOptions`, which only the opening run takes,
     * and which the config file a key reads holds.
     */
    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        $config = OwnConfig::in($this->project);
        $manifest = $this->project->absolute(ComposerInstalled::fileIn(Path::of(Manifest::VENDOR)));
        $versions = $config instanceof CannotJudge
            ? $config
            : Installed::versionsIn($manifest, ...$config->staticAnalysis());
        $platform = $versions instanceof CannotJudge ? $versions : Platform::ofRunner(
            $this->shell->run(Command::php(...Platform::describing())->withholding($withheld))->output(),
        );

        return $platform instanceof Platform
            ? Identity::of(BuiltinRunner::Infection->value, $versions, $platform->digest())
            : $platform;
    }

    /**
     * Infection lists `#[Holds]` as groups, raises its limit, and reuses the
     * map the plan handed each shard; it stops each mutant at its first
     * failing test, so it cannot record every killer. It runs a mutant per
     * core, across the threads a request asks for.
     */
    public function behaviour(): RunnerBehaviour
    {
        return RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)->runningPerCore();
    }

    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        $config = OwnConfig::in($this->project);

        return $config instanceof CannotJudge
            ? $config
            : Listing::groupsIn(
                $this->shell->run(Invocation::listingGroups($this->project, $config)->withholding($withheld)),
            );
    }

    /**
     * The map PHPUnit writes running the suite or a group under coverage, or
     * the gate's own map another job handed on.
     */
    public function coverage(CoverageRun|CoverageRead $request): CoverageMap|CannotJudge
    {
        if ($request instanceof CoverageRead) {
            return HandedMap::in($this->project, $request->directory());
        }

        $config = OwnConfig::in($this->project);
        $directory = $this->project->directory($request->directory());
        $covered = $config instanceof CannotJudge ? $config : $this->covered(
            Invocation::coverage($this->project, $config, $request->tests(), $directory)
                ->withholding($request->withheld()),
            $directory,
        );

        return $covered instanceof CannotJudge ? $covered : CoverageXml::read($this->project, $directory);
    }

    /** The files of the test classes whose tests cover the file: the classes Infection runs for its mutants. */
    public function judges(Path $file, CoverageMap $map): Paths
    {
        $classes = [];

        foreach ($map->testsCoveringFile($file) as $test) {
            $classes[TestMethod::classOf($test)] = true;
        }

        return $classes === [] ? Paths::none() : TestFiles::declaring($this->project, array_keys($classes));
    }

    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        $config = OwnConfig::in($this->project);

        if ($config instanceof CannotJudge) {
            return $config;
        }

        $this->held->forget();
        $coverage = $this->coverageFor($config, $request);

        return $coverage instanceof CannotJudge
            ? $coverage
            : $this->run($config)->of($request, $coverage, $this->cap);
    }

    /**
     * Each timed-out or skipped mutant the cap decided runs again with this
     * limit as the cap, as the invocation that made it asked: judged by its
     * tests, reading the coverage it read, withheld and timed as it was. The
     * rest are answered as they were.
     */
    public function retry(MutationRequest $request, Mutants $mutants, Seconds $limit): Mutants|CannotJudge
    {
        return $this->rerunning()->retry(Retrial::under($this->cap), $request, $mutants, $limit);
    }

    /** One mutant run again on its own, with what Infection printed (see Rerunning). */
    public function reproduce(
        Reproducible $mutant,
        WholeSuite|Group|Filter $judgedBy,
        Seconds $limit,
        Withheld $withheld,
    ): Reproduction|CannotJudge {
        return $this->rerunning()->reproduce($mutant, $judgedBy, $limit, $withheld);
    }

    public function markers(Paths $files): Markers|CannotJudge
    {
        $config = OwnConfig::in($this->project);

        return $config instanceof CannotJudge ? $config : NativeMarkers::in($this->project, $config, $files);
    }

    /**
     * The project's Infection config, whichever name it has, and the PHPUnit
     * config Infection runs with, in `phpUnit.configDir` where the project's
     * config sets it and in the root otherwise.
     */
    public function definitions(): Paths
    {
        $config = OwnConfig::in($this->project);
        $phpunit = $config instanceof CannotJudge
            ? PhpUnitConfig::candidatesIn(Path::root())
            : $config->phpUnitConfigs($this->project);

        return Paths::of(...OwnConfig::files(), ...$phpunit);
    }

    /**
     * Each test by the file that declares its class and its method's name,
     * as PHPUnit's JUnit log names it. Nothing runs, so nothing is withheld.
     */
    public function names(TestIds $tests, Withheld $withheld): TestNames
    {
        return Names::of($this->project, $tests);
    }

    /** Infection in a package's directory, where Composer installed it there. */
    public function rootedAt(Path $package): self|CannotJudge
    {
        $project = $this->project->in($package);

        return Invocation::runnableIn($project)
            ? new self(
                $project,
                $this->shell->in($project->root()),
                $this->cap,
                $this->nativeMarkersAllowed,
                $this->clock,
            )
            : CannotJudge::because(sprintf(self::NO_PROJECT, $package->value()));
    }

    /**
     * The coverage directory a run reads, which the adapter writes unless the
     * mutation run a run again follows left the same there: for a run judged by
     * the whole suite that reuses the map another job handed on, that map in
     * Infection's layout; otherwise PHPUnit's run of the tests that judge it.
     * A held path never reads a map of the whole suite.
     */
    private function coverageFor(OwnConfig $config, MutationRequest $request): DiskPath|CannotJudge
    {
        $reused = $request->coverage();
        $own = $this->ownCoverage();

        if ($reused instanceof Path && $request->judgedBy() instanceof WholeSuite) {
            return $this->held->handedOn($reused, fn(): DiskPath|CannotJudge => $this->handedOn($reused));
        }

        $run = Invocation::coverage($this->project, $config, $request->judgedBy(), $own)
            ->withholding($request->withheld());

        return $this->held->ranBy($run, fn(): DiskPath|CannotJudge => $this->covered($run, $own));
    }

    /** The map another job handed on in a directory, written into Infection's layout. */
    private function handedOn(Path $directory): DiskPath|CannotJudge
    {
        $map = HandedMap::in($this->project, $directory);

        return $map instanceof CannotJudge ? $map : CoverageLayout::write($this->project, $map, $this->ownCoverage());
    }

    /**
     * The directory, once PHPUnit has run under coverage into it, with no
     * earlier run's reports left.
     */
    private function covered(Command $run, DiskPath $directory): DiskPath|CannotJudge
    {
        foreach ([CoverageXml::indexIn($directory), $directory->child(Invocation::JUNIT)] as $report) {
            $fresh = $this->project->fresh($report->value());

            if ($fresh instanceof CannotJudge) {
                return $fresh;
            }
        }

        $ran = $this->shell->run($run);

        return $ran->succeeded() ? $directory : CannotJudge::because(sprintf(self::COVERAGE_FAILED, $ran->output()));
    }

    /** Mutants run again, reading the coverage their request would have read. */
    private function rerunning(): Rerunning
    {
        $covered = fn(OwnConfig $config, MutationRequest $request): DiskPath|CannotJudge
            => $this->coverageFor($config, $request);

        return new Rerunning($this->project, $this->shell, $this->nativeMarkersAllowed, $covered, $this->clock);
    }

    /** The directory the adapter runs PHPUnit under coverage into, for a run of its own. */
    private function ownCoverage(): DiskPath
    {
        return $this->project->directory(Path::of($this->project->own(Invocation::COVERAGE)));
    }

    private function run(OwnConfig $config): MutationRun
    {
        return new MutationRun($this->project, $this->shell, $config, $this->nativeMarkersAllowed);
    }
}
