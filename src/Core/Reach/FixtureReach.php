<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_merge;
use function count;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * What a change to a file of a fixture project reaches: what the tests that
 * use the project run, by the coverage map, and every tree of each module one
 * of them is inside; or every unit of its package, where they cannot be told
 * or no map says what they run. Each fixture's users and what they run are
 * read once, however many of its files changed.
 *
 * @internal
 */
final readonly class FixtureReach
{
    private const string FIXTURE
        = '`%s` is in the fixture `%s`, which %d tests use, so the %d files they run are reached.';

    /**
     * @param array<string, Paths|Reason>                             $users the users of each fixture project a change
     *                                                                       is in, by its path
     * @param array<string, array{Paths, list<array{Paths, Reason}>}> $runs  the files those users run, where the map
     *                                                                       can follow them, and the trees of each
     *                                                                       module one is inside, by the fixture's path
     */
    private function __construct(
        private Layout $layout,
        private Packages $packages,
        private Judges|NoMap $coverage,
        private array $users,
        private array $runs,
    ) {
    }

    /** What changes to fixture projects reach, among these changes. */
    public static function of(
        Layout $layout,
        Trees $trees,
        Judges|NoMap $coverage,
        SupportUsers $support,
        Changes $changes,
    ): self {
        $packages = Packages::of($trees);
        $users = $support->fixtures($layout)->ofChanges($changes, $packages);
        $runs = [];

        foreach ($users as $fixture => $tests) {
            if ($tests instanceof Paths && $coverage instanceof Judges) {
                $runs[$fixture] = self::ranBy($layout, $trees, $tests, $coverage);
            }
        }

        return new self($layout, $packages, $coverage, $users, $runs);
    }

    /** This reach, and what the tests that use each fixture project a change is in, as it is and as it was, run. */
    public function reach(Reach $reach, Change $change): Reach
    {
        foreach ($this->layout->fixturesOf($change) as $fixture) {
            $reach = $this->changed($reach, $change, $fixture);
        }

        return $reach;
    }

    private function changed(Reach $reach, Change $change, Path $fixture): Reach
    {
        $path = $change->pathWithin($fixture);
        $package = $this->packages->holding($fixture);
        $users = $this->users[$fixture->value()];

        if ($users instanceof Reason || $this->coverage instanceof NoMap) {
            $why = $users instanceof Reason
                ? $users
                : Reason::that(sprintf(TestReach::NO_MAP, $path->value(), TestReach::named($package)));

            return $reach->wholly(Paths::of($package->path()), $why);
        }

        [$files, $modules] = $this->runs[$fixture->value()];

        foreach ($modules as [$trees, $why]) {
            $reach = $reach->trees($trees, $why);
        }

        return $reach->files(
            $files,
            Reason::that(sprintf(self::FIXTURE, $path->value(), $fixture->value(), count($users), count($files))),
        );
    }

    /**
     * The files these tests run, by the coverage map, and the trees of each
     * module one of them is inside, with why they are reached.
     *
     * @return array{Paths, list<array{Paths, Reason}>}
     */
    private static function ranBy(Layout $layout, Trees $trees, Paths $tests, Judges $coverage): array
    {
        $run = [];
        $modules = [];

        foreach ($tests as $test) {
            $run[] = [...$coverage->filesRunBy($test)];
            $modules[] = TestReach::modulesOf($layout, $trees, $test);
        }

        return [Paths::of(...array_merge(...$run)), array_merge(...$modules)];
    }
}
