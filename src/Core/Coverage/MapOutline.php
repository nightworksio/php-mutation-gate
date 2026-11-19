<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_intersect_key;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * What the gate decides a coverage map by, without its lines: its tests in
 * their order, and the files each test ran a line of. The suites' admission
 * and the entries' keys are read from it (ADR-0119), whether the gate built
 * the map or an engine reads it from a runner's report; an engine's outline
 * also names each file as the report does.
 *
 * @internal
 */
final readonly class MapOutline
{
    /**
     * @param array<string, array<string, Path>> $ran   the files each test ran a line of, by its id and their paths
     * @param array<string, Path>                $named each file, by the name the report gives it
     */
    private function __construct(private TestIds $tests, private array $ran, private array $named)
    {
    }

    /** A map's outline, read from it once; each file named by its path. */
    public static function of(CoverageMap $map): self
    {
        $ran = [];
        $named = [];

        foreach ($map->files() as $file) {
            $named[$file->value()] = $file;

            foreach ($map->testsCoveringFile($file) as $test) {
                $ran[$test->value()][$file->value()] = $file;
            }
        }

        return new self($map->tests(), $ran, $named);
    }

    /**
     * An outline as an engine read it.
     *
     * @param array<string, array<string, Path>> $ran   the files each test ran a line of, by its id and their paths
     * @param array<string, Path>                $named each file, by the name the report gives it
     */
    public static function listed(TestIds $tests, array $ran, array $named): self
    {
        return new self($tests, $ran, $named);
    }

    /** The map's tests, in its order. */
    public function tests(): TestIds
    {
        return $this->tests;
    }

    /** A map of the tests alone, which a runner tells the tests of its test files from. */
    public function map(): CoverageMap
    {
        return CoverageMap::placed(LineTests::placed($this->tests));
    }

    /** Every file a test ran a line of. */
    public function ran(TestId $test): Paths
    {
        return Paths::of(...(array_key_exists($test->value(), $this->ran) ? $this->ran[$test->value()] : []));
    }

    /** Every file any of these tests ran a line of, each once. */
    public function ranBy(TestIds $tests): Paths
    {
        $files = [];

        foreach ($tests as $test) {
            $files += array_key_exists($test->value(), $this->ran) ? $this->ran[$test->value()] : [];
        }

        return Paths::of(...$files);
    }

    /**
     * Each file, by the name the report gives it.
     *
     * @return array<string, Path>
     */
    public function named(): array
    {
        return $this->named;
    }

    /**
     * This outline as an admission leaves it: each test leaving runs only
     * the files it stays on, and one that stays on none is gone.
     *
     * @param array<string, array<string, true>> $staying the paths of the files each test leaving stays on, by its id
     */
    public function leaving(array $staying): self
    {
        $tests = [];
        $ran = $this->ran;

        foreach ($this->tests as $test) {
            $id = $test->value();
            $kept = array_key_exists($id, $staying) ? array_intersect_key($this->ranAt($id), $staying[$id]) : [];
            $leaves = array_key_exists($id, $staying);

            if (! $leaves || $kept !== []) {
                $tests[] = $test;
            }

            if ($leaves) {
                $ran[$id] = $kept;
            }
        }

        return new self(TestIds::of(...$tests), $ran, $this->named);
    }

    /** @return array<string, Path> the files a test ran a line of, by their paths */
    private function ranAt(string $id): array
    {
        return array_key_exists($id, $this->ran) ? $this->ran[$id] : [];
    }
}
