<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_merge;
use function count;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * What a change to a test, to test support, to a fixture project or to
 * anything else reaches: a test file reaches what its changed tests run by
 * the coverage map, or what all its tests run where the change moved more or
 * `reach.changedTests` is `file` (ADR-0065), support and a fixture reach what
 * the tests that use them run, and anything else reaches nothing by itself.
 * A file moved into or out of a fixture also reaches what it reaches where it
 * is outside one.
 *
 * @internal
 */
final readonly class TestReach
{
    /** What the project at the root is called in a sentence. */
    public const string PROJECT = 'the project';

    /** Why a change reaches every unit of its package where no coverage map says what its tests run. */
    public const string NO_MAP = 'No coverage map says what `%s` runs, so every unit of %s is reached.';

    private const string GONE = '`%s` was deleted, so every unit of %s is reached.';

    private const string TEST = '`%s` changed, so the %d files its tests run are reached.';

    private const string TESTS = '`%s` changed %d of its %d tests, so the %d files they run are reached.';

    private const string UNTOUCHED
        = '`%s` changed none of the %d tests the coverage map places in it, so it reaches nothing by itself.';

    private const string UNPLACED
        = 'The coverage map places no test in `%s` by its id, so the %d files the runner judges by it are reached.';

    private const string SUPPORT = '`%s` is test support %d tests use, so the %d files they run are reached.';

    private const string MODULE = '`%s` is a test of the module %s, so every tree of it is reached.';

    private const string NOTHING = '`%s` reaches nothing by itself.';

    private function __construct(
        private Layout $layout,
        private Packages $packages,
        private Trees $trees,
        private Judges|NoMap $coverage,
        private Sources $sources,
        private SupportUsers $users,
        private TestRuns $runs,
        private FixtureReach $fixtures,
    ) {
    }

    /** The reach of these changes to tests and test support. */
    public static function of(
        Layout $layout,
        Trees $trees,
        Judges|NoMap $coverage,
        Sources $sources,
        Changes $changes,
        TestRuns $runs,
    ): self {
        $packages = Packages::of($trees);
        $users = SupportUsers::ofChanges($layout, $packages, $sources, $changes);
        $fixtures = FixtureReach::of($layout, $trees, $coverage, $users, $changes);

        return new self($layout, $packages, $trees, $coverage, $sources, $users, $runs, $fixtures);
    }

    public function reach(Reach $reach, Change $change): Reach
    {
        $path = $change->path();
        $package = $this->packages->holding($path);
        $inPackage = $path->relativeTo($package->path());
        $reach = $this->inFixtures($reach, $change);

        return match (true) {
            $this->layout->isInFixtures($change) => $reach,
            $this->layout->isTest($inPackage) => $this->testChanged($reach, $change, $package),
            $this->layout->isSupport($inPackage) => $this->supportChanged($reach, $change, $package),
            default => $reach->because(Reason::that(sprintf(self::NOTHING, $path->value()))),
        };
    }

    /** This reach, and what the tests that use each fixture project a change is in, as it is and as it was, run. */
    public function inFixtures(Reach $reach, Change $change): Reach
    {
        return $this->fixtures->reach($reach, $change);
    }

    /** A package as a reason names it: the project at the root, and any other by its path. */
    public static function named(Package $package): string
    {
        return $package->path()->equals(Path::root()) ? self::PROJECT : $package->path()->value();
    }

    /**
     * The trees of each module a test is inside, with why they are reached.
     *
     * @return list<array{Paths, Reason}>
     */
    public static function modulesOf(Layout $layout, Trees $trees, Path $test): array
    {
        $modules = [];

        foreach ($layout->modulesHolding($test) as $module) {
            $within = [];

            foreach ($trees as $tree) {
                if ($tree->path()->within($module)) {
                    $within[] = $tree->path();
                }
            }

            $modules[] = [Paths::of(...$within), Reason::that(sprintf(self::MODULE, $test->value(), $module->value()))];
        }

        return $modules;
    }

    private function testChanged(Reach $reach, Change $change, Package $package): Reach
    {
        $path = $change->path();
        $gone = $change->kind() === ChangeKind::Deleted;

        if ($gone || $this->coverage instanceof NoMap) {
            return $reach->wholly(
                Paths::of($package->path()),
                Reason::that(sprintf($gone ? self::GONE : self::NO_MAP, $path->value(), self::named($package))),
            );
        }

        $placed = $this->runs->in($path);
        $judged = $this->coverage->filesRunBy($path);

        $reached = match (true) {
            ! $this->runs->narrows() => $reach->files(
                $judged,
                Reason::that(sprintf(self::TEST, $path->value(), count($judged))),
            ),
            count($placed) === 0 => $reach->files(
                $judged,
                Reason::that(sprintf(self::UNPLACED, $path->value(), count($judged))),
            ),
            default => $this->narrowed($reach, $change, $placed, $judged),
        };

        return $this->withModules($reached, $path);
    }

    /**
     * This reach, with what the tests a change to a test file touched run, of
     * those the map places in it; or, where it touched them all, what the
     * runner judges by the file and what any of them runs.
     */
    private function narrowed(Reach $reach, Change $change, TestIds $placed, Paths $judged): Reach
    {
        $path = $change->path()->value();
        $touched = TestsChanged::of($change, $this->sources, $placed);

        if ($touched instanceof Widening) {
            $files = $judged->and($this->runs->filesRunBy($placed));

            return $reach->files($files, $touched->reason($path, count($files)));
        }

        $files = $this->runs->filesRunBy($touched);
        $why = count($touched) === 0
            ? sprintf(self::UNTOUCHED, $path, count($placed))
            : sprintf(self::TESTS, $path, count($touched), count($placed), count($files));

        return $reach->files($files, Reason::that($why));
    }

    private function supportChanged(Reach $reach, Change $change, Package $package): Reach
    {
        $path = $change->path();
        $users = $this->users->ofChanged($change, $this->sources, self::named($package));

        if ($users instanceof Reason || $this->coverage instanceof NoMap) {
            $why = $users instanceof Reason
                ? $users
                : Reason::that(sprintf(self::NO_MAP, $path->value(), self::named($package)));

            return $reach->wholly(Paths::of($package->path()), $why);
        }

        [$reach, $files] = $this->runBy($reach, $users, $this->coverage);

        return $reach->files(
            $files,
            Reason::that(sprintf(self::SUPPORT, $path->value(), count($users), count($files))),
        );
    }

    /**
     * This reach, with every tree of each module one of these tests is
     * inside, and the files the tests run, by the coverage map.
     *
     * @return array{Reach, Paths}
     */
    private function runBy(Reach $reach, Paths $tests, Judges $coverage): array
    {
        $run = [];

        foreach ($tests as $test) {
            $run[] = [...$coverage->filesRunBy($test)];
            $reach = $this->withModules($reach, $test);
        }

        return [$reach, Paths::of(...array_merge(...$run))];
    }

    /** This reach, and every tree of each module a changed test is inside. */
    private function withModules(Reach $reach, Path $test): Reach
    {
        foreach (self::modulesOf($this->layout, $this->trees, $test) as [$trees, $why]) {
            $reach = $reach->trees($trees, $why);
        }

        return $reach;
    }
}
