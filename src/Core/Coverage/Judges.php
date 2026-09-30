<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * The test files that run each covered file, as the runner selects them to
 * judge it: what a coverage map says once its tests are placed in their files.
 */
final readonly class Judges
{
    /** @param array<string, array{Path, Paths}> $judges each covered file with its test files, by its path */
    private function __construct(private array $judges)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, with the test files that judge a covered file, in place of any it had. */
    public function judging(Path $file, Paths $tests): self
    {
        $judges = $this->judges;
        $judges[$file->value()] = [$file, $tests];

        return new self($judges);
    }

    /** Every covered file a test file runs. */
    public function filesRunBy(Path $test): Paths
    {
        $files = Paths::none();

        foreach ($this->judges as [$file, $tests]) {
            $files = $tests->has($test) ? $files->with($file) : $files;
        }

        return $files;
    }
}
