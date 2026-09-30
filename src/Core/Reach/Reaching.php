<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * The rules that decide what a change reaches, in order; the first that
 * matches a changed path decides for it:
 *
 * 1. a file that decides how the gate runs reaches everything in its package
 *    and in the packages that depend on it, and a root file reaches every
 *    package; a CI definition that runs the gate does too, unless all its
 *    change moved is its action pins;
 * 2. a changed source file in a tree reaches its unit;
 * 3. a changed test reaches every unit its tests run, by the coverage map;
 * 4. changed test support reaches what the tests that use it run;
 * 5. anything else reaches nothing by itself.
 *
 * Where version control cannot tell what changed, everything is reached.
 */
final readonly class Reaching
{
    private const string CANNOT_TELL = '%s So every unit is reached.';

    private const string DECIDES = '`%s` decides how the gate runs, so every unit is reached.';

    private const string DECIDES_IN = '`%s` decides how the gate runs in %s, so every unit of %s is reached.';

    private const string PINS = '`%s` moved only the commits its actions are pinned at, so it reaches nothing.';

    private const string SOURCE = '`%s` changed, so its unit is reached.';

    private const string LEFT = '`%s` was deleted, so it leaves its tree with nothing to mutate.';

    public function __construct(private Layout $layout, private Trees $trees)
    {
    }

    /**
     * What these changes reach, given what the coverage map says the tests
     * run and the files the rules read.
     */
    public function of(Changes|CannotTell $changes, Judges|NoMap $coverage, Sources $sources): Reach
    {
        $packages = Packages::of($this->trees);
        $reach = Reach::nothing($packages);

        if ($changes instanceof CannotTell) {
            return $reach->everywhere(Reason::that(sprintf(self::CANNOT_TELL, $changes->why())));
        }

        $tests = TestReach::of($this->layout, $this->trees, $coverage, $sources, $changes);

        foreach ($changes as $change) {
            $reach = $this->withChange($reach, $change, $packages, $tests, $sources);
        }

        return $reach;
    }

    private function withChange(
        Reach $reach,
        Change $change,
        Packages $packages,
        TestReach $tests,
        Sources $sources,
    ): Reach {
        $path = $change->path();
        $deciding = $this->decidingIn($change, $packages);

        return match (true) {
            $deciding instanceof Path => $this->decided($reach, $deciding, $packages->holding($deciding), $packages),
            $this->layout->runsTheGate($path) => $this->definitionChanged($reach, $change, $sources),
            $this->isSource($path) => $this->sourceChanged($reach, $change),
            default => $tests->reach($reach, $change),
        };
    }

    /** The path of this change that decides how the gate runs in its package, or its paths where none does. */
    private function decidingIn(Change $change, Packages $packages): Path|Paths
    {
        $paths = Paths::of($change->path(), $change->previousPath());

        foreach ($paths as $path) {
            if ($this->layout->decides($path->relativeTo($packages->holding($path)->path()))) {
                return $path;
            }
        }

        return $paths;
    }

    private function isSource(Path $path): bool
    {
        foreach ($this->trees as $tree) {
            if ($path->isPhp() && $path->within($tree->path())) {
                return true;
            }
        }

        return false;
    }

    private function decided(Reach $reach, Path $path, Package $package, Packages $packages): Reach
    {
        if ($package->path()->equals(Path::root())) {
            return $reach->everywhere(Reason::that(sprintf(self::DECIDES, $path->value())));
        }

        $reached = $packages->withDependents($package);
        $named = implode(', ', array_map(static fn(Path $each): string => $each->value(), [...$reached]));

        return $reach->wholly(
            $reached,
            Reason::that(sprintf(self::DECIDES_IN, $path->value(), $package->path()->value(), $named)),
        );
    }

    private function definitionChanged(Reach $reach, Change $change, Sources $sources): Reach
    {
        $before = $sources->before($change->previousPath());
        $after = $sources->now($change->path());

        return $before instanceof Contents && $after instanceof Contents && Pins::onlyMoved($before, $after)
            ? $reach->because(Reason::that(sprintf(self::PINS, $change->path()->value())))
            : $reach->everywhere(Reason::that(sprintf(self::DECIDES, $change->path()->value())));
    }

    private function sourceChanged(Reach $reach, Change $change): Reach
    {
        $path = $change->path();

        return $change->kind() === ChangeKind::Deleted
            ? $reach->because(Reason::that(sprintf(self::LEFT, $path->value())))
            : $reach
                ->files(Paths::of($path), Reason::that(sprintf(self::SOURCE, $path->value())))
                ->withLines($path, $change->lines());
    }
}
