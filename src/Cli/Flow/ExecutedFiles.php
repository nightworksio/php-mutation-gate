<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** The files each test of a map executed: those it covers a line of, read from the map once. */
final readonly class ExecutedFiles
{
    /** @param array<array-key, array<array-key, Path>> $byTest each test's executed files, by its id and their paths */
    private function __construct(private array $byTest)
    {
    }

    public static function in(CoverageMap $map): self
    {
        $byTest = [];

        foreach ($map->files() as $file) {
            foreach ($map->testsCoveringFile($file) as $test) {
                $byTest[$test->value()][$file->value()] = $file;
            }
        }

        return new self($byTest);
    }

    /** Every file any of these tests executed, each once. */
    public function by(TestIds $tests): Paths
    {
        $files = [];

        foreach ($tests as $test) {
            $files += array_key_exists($test->value(), $this->byTest) ? $this->byTest[$test->value()] : [];
        }

        return Paths::of(...$files);
    }
}
