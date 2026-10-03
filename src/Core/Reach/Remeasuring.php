<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * What a kept coverage map measures again after a change (ADR-0010, decision
 * 1): each changed file of test cases, and each file of test cases that uses
 * changed test support (ADR-0005, decision 4, rule 4), whose entries are
 * replaced; or the whole suite, where a file that decides how the gate runs
 * changed (rule 1) or support acts on tests that never name it. A change to
 * anything else measures nothing again.
 */
final readonly class Remeasuring
{
    public function __construct(private Layout $layout, private Trees $trees)
    {
    }

    /** The test files these changes measure again, or the whole suite, by the files the rules read. */
    public function of(Changes $changes, Sources $sources): TestPaths|WholeSuite
    {
        $packages = Packages::of($this->trees);
        $users = SupportUsers::ofChanges($this->layout, $packages, $sources, $changes);
        $tests = Paths::none();

        foreach ($changes as $change) {
            $again = $this->againFor($change, $packages, $users, $sources);

            if ($again instanceof WholeSuite) {
                return $again;
            }

            $tests = Paths::of(...$tests, ...$again);
        }

        return TestPaths::of($tests);
    }

    /** The test files one change measures again, or the whole suite. */
    private function againFor(
        Change $change,
        Packages $packages,
        SupportUsers $users,
        Sources $sources,
    ): Paths|WholeSuite {
        $path = $change->path();
        $package = $packages->holding($path)->path();
        $inPackage = $path->relativeTo($package);
        $again = match (true) {
            $this->decides($change, $packages) => WholeSuite::tests(),
            $this->layout->isTest($inPackage) => Paths::of($path, $change->previousPath()),
            $this->layout->isSupport($inPackage) => $users->ofChanged($change, $sources, $package->value()),
            default => Paths::none(),
        };

        return $again instanceof Reason ? WholeSuite::tests() : $again;
    }

    /** Whether either path of a change decides how the gate runs, in its package or as a CI definition. */
    private function decides(Change $change, Packages $packages): bool
    {
        foreach (Paths::of($change->path(), $change->previousPath()) as $path) {
            if ($this->layout->decides($path, $packages->holding($path)->path()) || $this->layout->runsTheGate($path)) {
                return true;
            }
        }

        return false;
    }
}
