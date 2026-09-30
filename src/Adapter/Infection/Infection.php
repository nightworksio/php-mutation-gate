<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_keys;
use function getenv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as ComposerInstalled;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
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
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\Platform;
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
    /** The runner's name, which its identity and the directory it keeps its own files in carry. */
    public const string RUNNER = 'infection';

    private const string NO_PROJECT = '%s holds no project Infection can run: Infection is not installed there.';

    private const string COVERAGE_FAILED = "PHPUnit's coverage run failed. PHPUnit said:\n%s";

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Seconds $cap,
        private bool $nativeMarkersAllowed,
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
            ? Identity::of(self::RUNNER, $versions, $platform->digest())
            : $platform;
    }

    /**
     * Infection lists `#[Holds]` as groups, raises its limit, and reuses the
     * map the plan handed each shard; it stops each mutant at its first
     * failing test, so it cannot record every killer.
     */
    public function behaviour(): RunnerBehaviour
    {
        return RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection);
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
        $covered = $config instanceof CannotJudge
            ? $config
            : $this->covered($config, $request->tests(), $request->withheld(), $directory);

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

        $coverage = $this->coverageFor($config, $request);

        return $coverage instanceof CannotJudge
            ? $coverage
            : $this->run($config)->of($request, $coverage, $this->cap);
    }

    /**
     * Each timed-out or skipped mutant the cap decided runs again with this
     * limit as the cap, judged by the tests that judged its unit, and the rest
     * are answered as they were. The tests never see a variable withheld.
     */
    public function retry(
        Mutants $mutants,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
    ): Mutants|CannotJudge {
        $retrial = Retrial::under($this->cap);
        $again = $this->again($retrial, $mutants, $limit, $judgedBy, $withheld);

        return $again instanceof CannotJudge ? $again : $retrial->matched($mutants, $again);
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
            ? new self($project, $this->shell->in($project->root()), $this->cap, $this->nativeMarkersAllowed)
            : CannotJudge::because(sprintf(self::NO_PROJECT, $package->value()));
    }

    /**
     * The coverage directory a run reads, which the adapter always writes: for
     * a run judged by the whole suite that reuses the map another job handed
     * on, that map in Infection's layout; otherwise PHPUnit's run of the tests
     * that judge it. A held path never reads a map of the whole suite.
     */
    private function coverageFor(OwnConfig $config, MutationRequest $request): DiskPath|CannotJudge
    {
        $reused = $request->coverage();

        return $reused instanceof Path && $request->judgedBy() instanceof WholeSuite
            ? $this->handedOn($reused)
            : $this->covered($config, $request->judgedBy(), $request->withheld(), $this->ownCoverage());
    }

    /** The map another job handed on in a directory, written into Infection's layout. */
    private function handedOn(Path $directory): DiskPath|CannotJudge
    {
        $map = HandedMap::in($this->project, $directory);

        return $map instanceof CannotJudge ? $map : CoverageLayout::write($this->project, $map, $this->ownCoverage());
    }

    /**
     * The directory, once PHPUnit has run these tests under coverage into it,
     * never seeing a variable withheld, with no earlier run's reports left.
     */
    private function covered(
        OwnConfig $config,
        WholeSuite|Group|Filter $tests,
        Withheld $withheld,
        DiskPath $directory,
    ): DiskPath|CannotJudge {
        foreach ([CoverageXml::indexIn($directory), $directory->child(Invocation::JUNIT)] as $report) {
            $fresh = $this->project->fresh($report->value());

            if ($fresh instanceof CannotJudge) {
                return $fresh;
            }
        }

        $ran = $this->shell->run(
            Invocation::coverage($this->project, $config, $tests, $directory)->withholding($withheld),
        );

        return $ran->succeeded() ? $directory : CannotJudge::because(sprintf(self::COVERAGE_FAILED, $ran->output()));
    }

    private function again(
        Retrial $retrial,
        Mutants $mutants,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
    ): Mutants|CannotJudge {
        $runs = $retrial->runs($mutants);
        $config = $runs === [] ? Mutants::none() : OwnConfig::in($this->project);

        if (! $config instanceof OwnConfig) {
            return $config;
        }

        $coverage = $this->covered($config, $judgedBy, $withheld, $this->ownCoverage());

        return $coverage instanceof CannotJudge
            ? $coverage
            : $this->rerun($config, $coverage, $runs, $limit, $judgedBy, $withheld);
    }

    /**
     * Each file and mutator run again, as the retry asks: judged by its tests,
     * and allowed its limit as the cap, which is no deadline for the run.
     *
     * @param list<array{Path, string}> $runs
     */
    private function rerun(
        OwnConfig $config,
        DiskPath $coverage,
        array $runs,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
    ): Mutants|CannotJudge {
        $again = Mutants::none();

        foreach ($runs as [$file, $mutator]) {
            $request = MutationRequest::of(Paths::of($file), $judgedBy)
                ->onlyMutators(Mutators::named($mutator))
                ->withholding($withheld);
            $result = $this->run($config)->of($request, $coverage, $limit);

            if ($result instanceof CannotJudge) {
                return $result;
            }

            $again = Mutants::of(...$again, ...$result->mutants());
        }

        return $again;
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
