<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_keys;
use function file_get_contents;
use function getenv;
use function is_file;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as ComposerInstalled;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Mutant\DiffPatch;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
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
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\TestNames;
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

    private const string UNREAD = 'The gate cannot read %s, the file Infection mutated, to check its mutant.';

    private const string NOT_STARTED = "PHPUnit's run of no test, timing a mutant's start-up, failed. It said:\n%s";

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Seconds $cap,
        private bool $nativeMarkersAllowed,
        private CapFiles $files,
        private Clock $clock = new WallClock(),
        private HeldCoverage $held = new HeldCoverage(),
        private StaticAnalysis $analysis = StaticAnalysis::Infection,
    ) {
    }

    /**
     * The adapter in the project the gate runs in, from the options the flows
     * write (see Setup), writing its memory cap with these files.
     */
    public static function fromOptions(Options $options, CapFiles $files): self|Invalid
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
            analysis: $setup->analysis(),
            files: $files,
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
        $covered = $config instanceof CannotJudge
            ? $config
            : $this->covering()->run($config, $request->tests(), $request->withheld(), $directory);

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

    /**
     * A run of no test, timed from its start to its end, started as Infection
     * starts PHPUnit for a mutant of this file, the mutant an unchanged copy,
     * on a config that loads no test file.
     */
    public function startUp(Path $file, Withheld $withheld): Seconds|CannotJudge
    {
        $config = OwnConfig::in($this->project);
        $shaped = $config instanceof CannotJudge ? $config : StartUpConfig::written($this->project, $config, $file);
        $ran = match (true) {
            $config instanceof CannotJudge => $config,
            $shaped instanceof CannotJudge => $shaped,
            default => $this->shell->run(
                Invocation::startingUp($this->project, $config, $shaped)->withholding($withheld),
            ),
        };

        return match (true) {
            $ran instanceof CannotJudge => $ran,
            $ran->succeeded() => $ran->timed(),
            default => CannotJudge::because(sprintf(self::NOT_STARTED, $ran->output())),
        };
    }

    /** Every mutant of the requested files. */
    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        $config = OwnConfig::in($this->project);

        if ($config instanceof CannotJudge) {
            return $config;
        }

        $this->held->forget();
        $coverage = $this->covering()->of($config, $request);

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
        MutationRequest $request,
        Seconds $limit,
    ): Reproduction|CannotJudge {
        return $this->rerunning()->reproduce($mutant, $request, $limit);
    }

    /**
     * The mutant as Infection made it: its diff put back onto the file as the
     * project holds it, where Infection changed only what the mutation changed.
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
                $this->files,
                $this->clock,
                analysis: $this->analysis,
            )
            : CannotJudge::because(sprintf(self::NO_PROJECT, $package->value()));
    }

    /** Mutants run again, reading the coverage their request would have read. */
    private function rerunning(): Rerunning
    {
        $covered = fn(OwnConfig $config, MutationRequest $request): DiskPath|CannotJudge
            => $this->covering()->of($config, $request);

        return new Rerunning(
            $this->project,
            $this->shell,
            $this->files,
            $this->nativeMarkersAllowed,
            $this->analysis,
            $covered,
            $this->clock,
        );
    }

    /** The coverage each run reads, which the adapter writes. */
    private function covering(): Covering
    {
        return new Covering($this->project, $this->shell, $this->held);
    }

    private function run(OwnConfig $config): MutationRun
    {
        return new MutationRun(
            $this->project,
            $this->shell,
            $this->files,
            $config,
            $this->nativeMarkersAllowed,
            $this->analysis,
        );
    }
}
