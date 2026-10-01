<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Floor as NewCodeFloor;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\TimeoutTriage;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;

/**
 * A project the flows run over, and the adapters they run through: the fake
 * runner's library in `src`, one tree over it held to a floor of 50, a
 * checkout on `main` at `head`, a ledger kept in memory and a CI that runs
 * shard 1 of a push to `main`.
 */
final readonly class Flows
{
    /** The files of the project, as its working tree holds them. */
    public const array FILES = [
        'src/Money.php' => "<?php\n\nfinal class Money\n{\n}\n",
        'src/Held.php' => "<?php\n\nfinal class Held\n{\n}\n",
        'tests/MoneyTest.php' => "<?php\n\nit('adds', fn () => expect(1)->toBe(1))->group('mutation-canary');\n",
        'composer.json' => "{}\n",
    ];

    /** The commit the checkout is at. */
    public const string HEAD = '5eeca8f2d0b1c4e7a9f3b6d8e0c2a4f6b8d0e2c4';

    /** The default branch, as a checkout that fetched it holds it. */
    public const string MAIN = 'refs/remotes/origin/main';

    /** The gate's setup, with no config file, at the instant every test runs at. */
    public static function setup(): Setup
    {
        return new Setup(
            Absent::setting(),
            Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
            Digest::sha256Of('installed'),
            new StoppedClock(Configs::NOW),
        );
    }


    /**
     * The settings of a config the flows run on: the fake runner, unless one
     * of these parts names another, and these parts besides.
     */
    public static function settings(ConfiguredRunner|Report|NewCodeFloor|Setting ...$parts): Settings
    {
        $gate = Gate::configure()->runner(ConfiguredRunner::uses('fake'));
        $reports = [];

        foreach ($parts as $part) {
            $reports = $part instanceof Report ? [...$reports, $part] : $reports;
            $gate = match (true) {
                $part instanceof ConfiguredRunner => $gate->runner($part),
                $part instanceof Report => $gate->reporting(...$reports),
                $part instanceof NewCodeFloor => $gate->newCode($part),
                default => $gate->with($part),
            };
        }

        return Configs::built($gate);
    }

    /** A directory holding the project's files. */
    public static function project(): string
    {
        $project = Scratch::directory();

        foreach (self::FILES as $path => $contents) {
            Scratch::write($project, $path, $contents);
        }

        return $project;
    }

    /** The one tree the project has, `src`, held to a floor of 50. */
    public static function trees(): Trees
    {
        return Trees::of(Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())));
    }

    /** The project as git would list it: its working tree, nothing changed since `base`, and `main` fetched. */
    public static function checkout(): ChangeSourceFake
    {
        return new ChangeSourceFake(
            Revision::ref('base'),
            Changes::none(),
            [Revision::workingTree()->name() => self::FILES, 'base' => self::FILES, self::MAIN => self::FILES],
        );
    }

    /** A push to `main`, whose shard 1 this job runs. */
    public static function ci(): CiPlanFake
    {
        return new CiPlanFake(ShardId::of(1), RunOn::at(Scope::branch('main'), Scope::branch('main')));
    }

    /**
     * The adapters over a project, each port a fake unless one of these is
     * that port, started in these environment variables.
     *
     * @param array<string, string> $environment
     */
    public static function adapters(string $project, array $environment = [], object ...$ports): Adapters
    {
        $ci = self::given(CiPlan::class, self::ci(), $ports);
        $checker = self::checker($ports);
        $withheld = Withheld::standard()->and(CiPlanFake::withheld());

        return new Adapters(
            self::given(Runner::class, RunnerFake::ofTheFixture(), $ports),
            $checker,
            $checker instanceof StaticChecker ? $checker->identity($withheld) : $checker,
            self::given(TreeSource::class, new TreeSourceFake(self::trees()), $ports),
            self::given(ProofStore::class, new ProofStoreFake(), $ports),
            self::given(CostModel::class, new CostModelFake(Seconds::of(1.0)), $ports),
            $ci,
            self::given(ChangeSource::class, self::checkout(), $ports),
            self::given(Repository::class, RepositoryFake::onMain(Revision::ref(self::HEAD)), $ports),
            Directory::at($project),
            Variables::of($environment),
            $withheld,
            Processes::of(2),
            self::given(Engine::class, NotGiven::value(), $ports),
        );
    }


    /**
     * The fake runner's mutants of one file of the project, as a shard leaves
     * them: each whose time ran out timed by the tests covering it.
     */
    public static function mutantsOf(string $file): Mutants
    {
        $mutants = RunnerFake::ofTheFixture()
            ->mutate(MutationRequest::of(Paths::of(Path::of($file)), WholeSuite::tests()))
            ->mutants();

        return TimeoutTriage::timed($mutants, self::map());
    }

    /** The coverage map the fake runner measures of the project. */
    public static function map(): CoverageMap
    {
        return RunnerFake::ofTheFixture()->coverage(CoverageRun::of(WholeSuite::tests(), Workspace::coverage()));
    }

    /** A checkout that cannot tell where it stands. */
    public static function lost(): RepositoryFake
    {
        $lost = CannotTell::because('git is not installed.');

        return new RepositoryFake($lost, $lost, $lost, $lost);
    }

    /**
     * The static analyser among these ports, or none.
     *
     * @param array<object> $ports
     */
    private static function checker(array $ports): StaticChecker|NoAnalyser
    {
        foreach ($ports as $given) {
            if ($given instanceof StaticChecker) {
                return $given;
            }
        }

        return NoAnalyser::configured();
    }

    /**
     * The one of these ports that is this port, or the fake.
     *
     * @template T of object
     *
     * @param  class-string<T> $port
     * @param  T               $fake
     * @param  array<object>   $ports
     * @return T
     */
    private static function given(string $port, object $fake, array $ports): object
    {
        foreach ($ports as $given) {
            if ($given instanceof $port) {
                return $given;
            }
        }

        return $fake;
    }
}
