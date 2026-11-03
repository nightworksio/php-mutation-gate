<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_key_exists;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * What each test of the changed test files runs, by the coverage map's id
 * of it (ADR-0065): each test the runner places in one of those files, with
 * the files it covers. Where
 * `reach.changedTests` is `file`, it holds no test and narrows nothing.
 *
 * @internal
 */
final readonly class TestRuns
{
    /**
     * @param array<string, array<int, true>> $runBy the place of each file each test covers, by the test's id
     * @param list<Path>                      $files each file the map covers, by its place
     */
    private function __construct(
        private bool $narrows,
        private TestPlaces $places,
        private array $runBy,
        private array $files,
    ) {
    }

    /** No test, for a run whose changed test files each reach every unit their tests run. */
    public static function wholeFiles(): self
    {
        return new self(narrows: false, places: TestPlaces::none(), runBy: [], files: []);
    }

    /** What the tests the runner placed in these test files cover, by this map. */
    public static function of(CoverageMap $map, TestPlaces $places): self
    {
        $wanted = self::placedIn($places);
        $files = [];

        foreach ($map->files() as $place => $file) {
            $files[] = $file;

            foreach ($map->testsCoveringFile($file) as $test) {
                if (array_key_exists($test->value(), $wanted)) {
                    $wanted[$test->value()][$place] = true;
                }
            }
        }

        return new self(narrows: true, places: $places, runBy: $wanted, files: $files);
    }

    /** Whether a changed test file reaches only what its changed tests run, read from these. */
    public function narrows(): bool
    {
        return $this->narrows;
    }

    /** The map's tests the runner places in a test file; none where it placed none there. */
    public function in(Path $file): TestIds
    {
        return $this->places->in($file);
    }

    /** Every file one of these tests covers, each once. */
    public function filesRunBy(TestIds $tests): Paths
    {
        $places = [];

        foreach ($tests as $test) {
            $places += array_key_exists($test->value(), $this->runBy) ? $this->runBy[$test->value()] : [];
        }

        return Paths::of(...array_map(fn(int $place): Path => $this->files[$place], array_keys($places)));
    }

    /** @return array<string, array<int, true>> each test placed in one of the files, covering none yet, by its id */
    private static function placedIn(TestPlaces $places): array
    {
        $placed = [];

        foreach ($places->files() as $file) {
            foreach ($places->in($file) as $test) {
                $placed[$test->value()] = [];
            }
        }

        return $placed;
    }
}
