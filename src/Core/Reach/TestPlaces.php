<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_key_exists;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * Every test file of the suite, each with the coverage map's tests it holds,
 * as the runner places a test in its file; a file the map holds no test of
 * holds none. It is held both ways round, so the file of a test is a lookup.
 */
final readonly class TestPlaces
{
    /**
     * @param array<string, array{Path, TestIds}> $files each test file with its tests, by its path
     * @param array<string, Path>                 $fileOf the file of each test, by the test's id
     */
    private function __construct(private array $files, private array $fileOf)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** These, with a test file and the map's tests it holds, in place of any it held. */
    public function placing(Path $file, TestIds $tests): self
    {
        $files = $this->files;
        $fileOf = $this->fileOf;

        foreach (array_key_exists($file->value(), $files) ? $files[$file->value()][1] : TestIds::none() as $test) {
            unset($fileOf[$test->value()]);
        }

        foreach ($tests as $test) {
            $fileOf[$test->value()] = $file;
        }

        $files[$file->value()] = [$file, $tests];

        return new self($files, $fileOf);
    }

    /** Every test file, in the order they were placed. */
    public function files(): Paths
    {
        $files = Paths::none();

        foreach ($this->files as [$file]) {
            $files = $files->with($file);
        }

        return $files;
    }

    /** The map's tests a file holds; none for a file that is not a test file, or holds no test of the map. */
    public function in(Path $file): TestIds
    {
        return array_key_exists($file->value(), $this->files) ? $this->files[$file->value()][1] : TestIds::none();
    }

    /**
     * The files that hold some of these tests, each with those of them it
     * holds; a test placed in no file is in none.
     *
     * @return list<array{Path, TestIds}>
     */
    public function holding(TestIds $tests): array
    {
        $held = [];

        foreach ($tests as $test) {
            if (array_key_exists($test->value(), $this->fileOf)) {
                $file = $this->fileOf[$test->value()];
                $before = array_key_exists($file->value(), $held) ? $held[$file->value()][1] : TestIds::none();
                $held[$file->value()] = [$file, $before->with($test)];
            }
        }

        return array_values($held);
    }
}
