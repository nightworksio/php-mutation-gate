<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Key\EntryKeying;
use NightWorksIO\MutationGate\Core\Test\Role;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * The project's test files, each with the key of its coverage entries
 * (ADR-0023, decision 1): over what every entry reads, then the test file,
 * the files its tests executed by a map, what runs before every test, and
 * every file those name. A test file's tests are those the runner names after
 * it in the map.
 */
final readonly class CoverageEntries
{
    private function __construct(
        private Adapters $adapters,
        private EntryKeying $keying,
        private Paths $testFiles,
        private EveryEntryReads $reads,
    ) {
    }

    /** The entries of a project's test files, keyed as the project stands; or why they cannot be. */
    public static function of(
        Adapters $adapters,
        Settings $settings,
        Setup $setup,
        Inventory $inventory,
    ): self|CannotJudge {
        $base = Keying::coverageBaseOf($adapters, $settings, $setup, $inventory->suite);
        $names = $base instanceof Digest ? new NameGraph($adapters)->of($inventory->suite) : $base;

        if ($names instanceof CannotJudge) {
            return $names;
        }

        $cases = [];
        $always = [];
        $reads = EveryEntryReads::of($inventory, Keying::exceptions($adapters, $settings, $setup), $setup->configFile);

        foreach ($inventory->suite->files() as $file) {
            $path = $file->fingerprint()->path();
            $cases = $file->role() === Role::TestCase ? [...$cases, $path] : $cases;
            $always = $reads->isReadByEvery($path) ? [...$always, $path] : $always;
        }

        $keyed = EntryKeying::of($base, $names, $inventory->files, Paths::of(...$always));

        return new self($adapters, $keyed, Paths::of(...$cases), $reads);
    }

    /** Every file of test cases, as the project stands. */
    public function testFiles(): Paths
    {
        return $this->testFiles;
    }

    /** The first file of a change, by either of its paths, that every entry reads; none where none is. */
    public function firstReadByEvery(Changes $changes): Path|NotGiven
    {
        return $this->reads->firstIn($changes);
    }

    /** The tests of a map these test files hold, by the runner's rules for naming a test after its file. */
    public function testsIn(Paths $files, CoverageMap $map): TestIds|CannotJudge
    {
        return $this->adapters->runner->testsIn($files, $map);
    }

    /** The key of a test file's entries, by the files its tests executed in a map; or why its tests are unknown. */
    public function keyOf(Path $file, CoverageMap $map, ExecutedFiles $executed): Digest|CannotJudge
    {
        $tests = $this->testsIn(Paths::of($file), $map);

        return $tests instanceof TestIds ? $this->keying->keyOf($file, $executed->by($tests)) : $tests;
    }

    /** The key of every test file's entries by a map; or why a file's tests are unknown. */
    public function keysOf(CoverageMap $map): EntryKeys|CannotJudge
    {
        $executed = ExecutedFiles::in($map);
        $keys = EntryKeys::none();

        foreach ($this->testFiles as $file) {
            $key = $this->keyOf($file, $map, $executed);

            if ($key instanceof CannotJudge) {
                return $key;
            }

            $keys = $keys->with($file, $key);
        }

        return $keys;
    }
}
