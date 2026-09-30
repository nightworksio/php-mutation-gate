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
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * What a change to a test, to test support or to anything else reaches: a
 * test reaches what it runs by the coverage map, support reaches what the
 * tests that use it run, and anything else reaches nothing by itself.
 */
final readonly class TestReach
{
    /** What the project at the root is called in a sentence. */
    private const string PROJECT = 'the project';

    private const string GONE = '`%s` was deleted, so every unit of %s is reached.';

    private const string NO_MAP = 'No coverage map says what `%s` runs, so every unit of %s is reached.';

    private const string TEST = '`%s` changed, so the %d files its tests run are reached.';

    private const string SUPPORT = '`%s` is test support %d tests use, so the %d files they run are reached.';

    private const string MODULE = '`%s` is a test of the module %s, so every tree of it is reached.';

    private const string NOTHING = '`%s` reaches nothing by itself.';

    private const string UNREAD
        = '`%s` cannot be read, so what it declares is not known, and every unit of %s is reached.';

    private function __construct(
        private Layout $layout,
        private Packages $packages,
        private Trees $trees,
        private Judges|NoMap $coverage,
        private Sources $sources,
        private SupportUsers $users,
    ) {
    }

    /**
     * The reach of these changes to tests and test support. Every PHP file
     * on disk is read for who uses support only where some of the changes
     * are to support.
     */
    public static function of(
        Layout $layout,
        Trees $trees,
        Judges|NoMap $coverage,
        Sources $sources,
        Changes $changes,
    ): self {
        $packages = Packages::of($trees);
        $read = self::changesSupport($layout, $packages, $changes) ? $sources : Sources::none();

        return new self(
            $layout,
            $packages,
            $trees,
            $coverage,
            $sources,
            SupportUsers::in($layout, $packages, $read),
        );
    }

    public function reach(Reach $reach, Change $change): Reach
    {
        $path = $change->path();
        $package = $this->packages->holding($path);
        $inPackage = $path->relativeTo($package->path());

        return match (true) {
            $this->layout->isTest($inPackage) => $this->testChanged($reach, $change, $package),
            $this->layout->isSupport($inPackage) => $this->supportChanged($reach, $change, $package),
            default => $reach->because(Reason::that(sprintf(self::NOTHING, $path->value()))),
        };
    }

    private function testChanged(Reach $reach, Change $change, Package $package): Reach
    {
        $path = $change->path();
        $gone = $change->kind() === ChangeKind::Deleted;

        if ($gone || $this->coverage instanceof NoMap) {
            return $reach->wholly(
                Paths::of($package->path()),
                Reason::that(sprintf($gone ? self::GONE : self::NO_MAP, $path->value(), $this->named($package))),
            );
        }

        $files = $this->coverage->filesRunBy($path);
        $reason = Reason::that(sprintf(self::TEST, $path->value(), count($files)));

        return $this->withModules($reach->files($files, $reason), $path);
    }

    private function supportChanged(Reach $reach, Change $change, Package $package): Reach
    {
        $path = $change->path();
        $declared = $this->declaredBy($change, $package);
        $users = $declared instanceof Reason ? $declared : $this->users->of($path, $declared, $this->named($package));

        if ($users instanceof Reason || $this->coverage instanceof NoMap) {
            $why = $users instanceof Reason
                ? $users
                : Reason::that(sprintf(self::NO_MAP, $path->value(), $this->named($package)));

            return $reach->wholly(Paths::of($package->path()), $why);
        }

        $run = [];

        foreach ($users as $test) {
            $run[] = [...$this->coverage->filesRunBy($test)];
            $reach = $this->withModules($reach, $test);
        }

        $files = Paths::of(...array_merge(...$run));

        return $reach->files(
            $files,
            Reason::that(sprintf(self::SUPPORT, $path->value(), count($users), count($files))),
        );
    }

    /**
     * What a changed piece of support declares, on disk and at the base; or,
     * where neither can be read, why every unit of its package is reached,
     * since the tests that use it cannot be told.
     */
    private function declaredBy(Change $change, Package $package): Names|Reason
    {
        $declared = Names::of();
        $read = false;

        $versions = [$this->sources->now($change->path()), $this->sources->before($change->previousPath())];

        foreach ($versions as $version) {
            if ($version instanceof Contents) {
                $declared = $declared->merge(PhpFile::read($version)->declares());
                $read = true;
            }
        }

        return $read
            ? $declared
            : Reason::that(sprintf(self::UNREAD, $change->path()->value(), $this->named($package)));
    }

    /** This reach, and every tree of each module a changed test is inside. */
    private function withModules(Reach $reach, Path $test): Reach
    {
        foreach ($this->layout->modulesHolding($test) as $module) {
            $trees = [];

            foreach ($this->trees as $tree) {
                if ($tree->path()->within($module)) {
                    $trees[] = $tree->path();
                }
            }

            $reach = $reach->trees(
                Paths::of(...$trees),
                Reason::that(sprintf(self::MODULE, $test->value(), $module->value())),
            );
        }

        return $reach;
    }

    /** Whether any of these changes is to test support. */
    private static function changesSupport(Layout $layout, Packages $packages, Changes $changes): bool
    {
        foreach ($changes as $change) {
            $path = $change->path();

            if ($layout->isSupport($path->relativeTo($packages->holding($path)->path()))) {
                return true;
            }
        }

        return false;
    }

    private function named(Package $package): string
    {
        return $package->path()->equals(Path::root()) ? self::PROJECT : $package->path()->value();
    }
}
