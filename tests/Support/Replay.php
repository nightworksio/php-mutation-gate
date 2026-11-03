<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\Config\ChangedTests;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reaching;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\TestRuns;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree as SourceTree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use RuntimeException;

use function sprintf;

/**
 * A merged pull request of this repository, replayed through the reach
 * rules and the shard cut from what its run recorded (ReplayFiles), in the
 * gate's own layout: its trees, its test directories, its runner's
 * definitions and its CI definition.
 */
final readonly class Replay
{
    /** The size, the setup and the most of the gate's own shards. */
    private const int SECONDS = 600;

    private const int MOST = 20;

    /** The gate's own trees. */
    private const array TREES = ['src', 'plugins/default/src', 'plugins/laravel/src', 'plugins/symfony/src', 'plugins/security/src'];

    /** The gate's own test directories, as its PHPUnit config names them, a plugin's by its name. */
    private const array SUITES = ['tests/Unit', 'tests/Process', 'tests/Contract', 'tests/Arch', 'tests/PHPStan'];

    private const array PLUGINS = ['default', 'laravel', 'symfony', 'security'];

    /** What a suite's test files end in. */
    private const string SUFFIX = 'Test.php';

    /** The files that define the gate's runner, Pest, and the CI definition that runs it. */
    private const array DEFINITIONS = ['tests/Pest.php', 'phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

    private const string WORKFLOW = '.github/workflows/mutation-gate.yml';

    private function __construct(private ReplayFiles $files)
    {
    }

    public static function of(string $name): self
    {
        return new self(ReplayFiles::of($name));
    }

    /** What the pull request reaches, where `reach.changedTests` is so. */
    public function reach(ChangedTests $mode): Reach
    {
        return $this->reaching($this->runs($mode), $this->files->sources());
    }

    /** The same, with one changed test file at the merge as this text instead. */
    public function reachWith(ChangedTests $mode, string $file, string $now): Reach
    {
        return $this->reaching($this->runs($mode), $this->files->sources()->withNow(Path::of($file), Contents::of($now)));
    }

    /** The same, narrowed, where the map holds no test of one changed test file by its id. */
    public function reachUnplacing(string $file): Reach
    {
        return $this->reaching(TestRuns::of($this->files->map(), $this->files->places($file)), $this->files->sources());
    }

    /** A changed test file as it is at the merge. */
    public function now(string $file): string
    {
        return $this->files->now($file);
    }

    /** How many of the units the run planned this reach reaches. */
    public function reached(Reach $reach): int
    {
        return count($this->unitsOf($reach));
    }

    /** How many shards the gate cuts the units this reach reaches into, by their learned costs. */
    public function shards(Reach $reach): int
    {
        $root = Package::at(Path::root());
        $weighed = [];

        foreach ($this->unitsOf($reach) as $unit => $seconds) {
            $weighed[] = Weighed::of(Unit::file(Path::of($unit)), $root, Estimated::of(Seconds::of($seconds), CostBasis::Learned));
        }

        $cut = Cut::bySize(self::SECONDS, Seconds::minutes(1), self::MOST)
            ->opening(Seconds::of($this->files->opening()))
            ->cut(Workload::of(...$weighed), $this->trees());

        return $cut instanceof Shards ? count($cut) : throw new RuntimeException($cut->why());
    }

    private function runs(ChangedTests $mode): TestRuns
    {
        return $mode === ChangedTests::Narrow ? TestRuns::of($this->files->map(), $this->files->places()) : TestRuns::wholeFiles();
    }

    private function reaching(TestRuns $runs, Sources $sources): Reach
    {
        $suites = [...self::SUITES, ...array_map(static fn(string $plugin): string => sprintf('plugins/%s/tests', $plugin), self::PLUGINS)];
        $layout = Layout::standard(Paths::of(...array_map(Path::of(...), self::DEFINITIONS)))
            ->testedIn(...array_map(static fn(string $suite): SuiteDirectory => SuiteDirectory::of(Path::of($suite), self::SUFFIX), $suites))
            ->runBy(Glob::of(self::WORKFLOW));

        foreach ($this->files->everything() as $glob) {
            $layout = $layout->decidedAlsoBy($glob);
        }

        return new Reaching($layout, $this->trees())->of($this->files->changes(), $this->files->judges(), $sources, $runs);
    }

    /** @return array<string, float> each unit the run planned that this reach reaches, with its cost */
    private function unitsOf(Reach $reach): array
    {
        $reached = [];

        foreach ($this->files->costs() as $unit => $cost) {
            $reached += $reach->reaches(Unit::file(Path::of((string) $unit))) ? [(string) $unit => $cost] : [];
        }

        return $reached;
    }

    private function trees(): Trees
    {
        $root = Package::at(Path::root());

        return Trees::of(...array_map(
            static fn(string $tree): SourceTree => SourceTree::at(Path::of($tree), Undeclared::floor(), $root),
            self::TREES,
        ));
    }
}
