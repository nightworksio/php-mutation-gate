<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_key_exists;
use function array_keys;
use function array_map;
use function asort;
use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * The test files that run each covered file, as the runner selects them to
 * judge it: what a coverage map says once its tests are placed in their files.
 * It is held both ways round, so the files a test runs are a lookup.
 */
final readonly class Judges
{
    /**
     * @param array<string, array{Path, Paths, int}> $judges each covered file with its test files and its place in
     *                                                       the order the files were first judged, by its path
     * @param array<string, array<string, int>>      $runBy  the place of each covered file each test file runs, by
     *                                                       the test file's path and then the covered file's
     */
    private function __construct(private array $judges, private array $runBy)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** These, with the test files that judge a covered file, in place of any it had. */
    public function judging(Path $file, Paths $tests): self
    {
        $judges = $this->judges;
        $runBy = $this->runBy;
        [, $before, $place] = array_key_exists($file->value(), $judges)
            ? $judges[$file->value()]
            : [$file, Paths::none(), count($judges)];

        foreach ($before as $test) {
            unset($runBy[$test->value()][$file->value()]);
        }

        foreach ($tests as $test) {
            $runBy[$test->value()][$file->value()] = $place;
        }

        $judges[$file->value()] = [$file, $tests, $place];

        return new self($judges, $runBy);
    }

    /** Every covered file a test file runs, in the order the files were first judged. */
    public function filesRunBy(Path $test): Paths
    {
        $places = array_key_exists($test->value(), $this->runBy) ? $this->runBy[$test->value()] : [];
        asort($places);

        return Paths::of(...array_map(fn(int|string $file): Path => $this->judges[$file][0], array_keys($places)));
    }
}
