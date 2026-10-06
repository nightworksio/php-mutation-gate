<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * ADR-0005's rules read backwards, for the tests a change can make fail
 * (ADR-0020, decision 2); the first that matches a changed path decides for
 * it:
 *
 * 1. a file that decides how the gate runs reaches every test, unless it
 *    decides as it did at the base, and so does a CI definition that runs
 *    the gate, unless its change moved only comments, blank lines and action
 *    pins;
 * 2. a source file in a tree reaches what {@see SourceTests} says;
 * 3. a changed test reaches itself;
 * 4. changed test support reaches the tests that use it;
 * 5. a file `proofs.ignore` matches reaches no test;
 * 6. any other file reaches every test, since no rule can say which read it.
 */
final readonly class Affecting
{
    /** Why every test is listed where the tests that use a piece of support cannot be told. */
    public const string UNTOLD = 'The tests that use `%s` cannot be told, so every test is listed.';
    private const string DECIDES = '`%s` decides how the gate runs, so every test is listed.';

    private const string RUNS_ALIKE
        = '`%s` changed only its comments, blank lines or action pins, so it lists no test.';

    private const string DECIDES_ALIKE = '`%s` changed nothing that decides how the gate runs, so it lists no test.';

    private const string TEST = '`%s` changed.';

    private const string GONE = '`%s` was deleted, so there is nothing of it to run.';

    private const string SUPPORT = '`%s` is test support these tests use.';

    private const string IGNORED = '`%s` matches proofs.ignore, so it lists no test.';

    private const string OTHER = 'No rule says which tests read `%s`, so every test is listed.';

    public function __construct(
        private Layout $layout,
        private Trees $trees,
        private Globs $ignored,
        private SourceTests $sources,
        private SupportUsers $users,
    ) {
    }

    /** The tests these changes reach, read from these files, over a suite whose test files hold the map's tests so. */
    public function of(Changes $changes, Sources $files, TestPlaces $places): AffectedTests
    {
        $packages = Packages::of($this->trees);
        $affected = AffectedTests::none($places);

        foreach ($changes as $change) {
            $affected = $affected->and($this->ofChange($change, $packages, $files, AffectedTests::none($places)));
        }

        return $affected;
    }

    private function ofChange(Change $change, Packages $packages, Sources $files, AffectedTests $none): AffectedTests
    {
        $path = $change->path();
        $package = $packages->holding($path);
        $inPackage = $path->relativeTo($package->path());
        $deciding = $this->decidingIn($change, $packages);

        return match (true) {
            $deciding instanceof Path && $files->decidesAlike($deciding) => $none->because(
                $this->why(self::DECIDES_ALIKE, $deciding),
            ),
            $deciding instanceof Path => $none->all($this->why(self::DECIDES, $deciding)),
            $this->layout->runsTheGate($path) => $this->definitionChanged($none, $change, $files),
            $this->isSource($path) => $this->sources->of($change, $this->named($package)),
            $this->layout->isTest($inPackage) => $change->kind() === ChangeKind::Deleted
                ? $none->because($this->why(self::GONE, $path))
                : $none->wholly($path, $this->why(self::TEST, $path)),
            $this->layout->isSupport($inPackage) => $this->supportChanged($none, $change, $files, $package),
            $this->ignored->matches($path) => $none->because($this->why(self::IGNORED, $path)),
            default => $none->all($this->why(self::OTHER, $path)),
        };
    }

    /** The path of this change, as it is or was, that decides how the gate runs in its package, where one does. */
    private function decidingIn(Change $change, Packages $packages): Path|NotDeciding
    {
        foreach (Paths::of($change->path(), $change->previousPath()) as $path) {
            if ($this->layout->decides($path, $packages->holding($path)->path())) {
                return $path;
            }
        }

        return NotDeciding::change();
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

    private function definitionChanged(AffectedTests $none, Change $change, Sources $files): AffectedTests
    {
        $before = $files->before($change->previousPath());
        $after = $files->now($change->path());

        return $before instanceof Contents && $after instanceof Contents && AsItRuns::alike($before, $after)
            ? $none->because($this->why(self::RUNS_ALIKE, $change->path()))
            : $none->all($this->why(self::DECIDES, $change->path()));
    }

    private function supportChanged(
        AffectedTests $none,
        Change $change,
        Sources $files,
        Package $package,
    ): AffectedTests {
        $path = $change->path();
        $users = $this->users->ofChanged($change, $files, $this->named($package));

        if ($users instanceof Reason) {
            return $none->all($this->why(self::UNTOLD, $path));
        }

        $affected = $none;

        foreach ($users as $test) {
            $affected = $affected->wholly($test, $this->why(self::SUPPORT, $path));
        }

        return $affected;
    }

    private function why(string $reason, Path $path): Reason
    {
        return Reason::that(sprintf($reason, $path->value()));
    }

    /** A package as a reason of the support rules names it. */
    private function named(Package $package): string
    {
        return $package->path()->equals(Path::root()) ? TestReach::PROJECT : $package->path()->value();
    }
}
