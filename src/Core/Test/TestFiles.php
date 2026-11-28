<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * The test file each test of a suite is in, as a request tells its runner:
 * a runner that loads every test file to run a few tests can load the files
 * of those tests alone.
 */
final readonly class TestFiles
{
    /** @param array<string, Path> $fileOf the file of each test, by the test's id */
    private function __construct(private array $fileOf)
    {
    }

    /** No test's file. */
    public static function none(): self
    {
        return new self([]);
    }

    /** These, with these tests in this file. */
    public function placing(Path $file, TestIds $tests): self
    {
        $fileOf = $this->fileOf;

        foreach ($tests as $test) {
            $fileOf[$test->value()] = $file;
        }

        return new self($fileOf);
    }

    /** How many tests have a file. */
    public function count(): int
    {
        return count($this->fileOf);
    }

    /**
     * The files these tests are in, each once, in the order the tests name
     * them; none where any of them has no file, or no test is given, so a
     * run of them never loads fewer files than it needs.
     */
    public function of(TestIds $tests): Paths
    {
        $files = [];

        foreach ($tests as $test) {
            if (! array_key_exists($test->value(), $this->fileOf)) {
                return Paths::none();
            }

            $files[$this->fileOf[$test->value()]->value()] = $this->fileOf[$test->value()];
        }

        return Paths::of(...$files);
    }
}
