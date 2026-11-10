<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\Saved;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * A quick round of `watch` (ADR-0146): the files changed since the round
 * before, whose coverage it takes from that round's map and the static graph
 * instead of measuring it again.
 *
 * @internal
 */
final readonly class QuickRound
{
    private function __construct(private Paths $changed)
    {
    }

    /** The round after one that left its map, with each path these changes name, by its name now and before. */
    public static function after(Changes $changes): self
    {
        $paths = Paths::none();

        foreach ($changes as $change) {
            $paths = $paths->with($change->path())->with($change->previousPath());
        }

        return new self($paths);
    }

    /** The files changed since the round before, by their names now and before. */
    public function changed(): Paths
    {
        return $this->changed;
    }

    /**
     * What the round takes first: the files saved, and the files a map shows
     * the tests of the saved test files running; or why their tests are unknown.
     */
    public function saved(CoverageEntries $entries, CoverageMap $map): Saved|CannotJudge
    {
        $tests = [];

        foreach ($this->changed as $path) {
            $tests = $entries->testFiles()->has($path) ? [...$tests, $path] : $tests;
        }

        $retesting = $tests === [] ? TestIds::none() : $entries->testsIn(Paths::of(...$tests), $map);

        if (! $retesting instanceof TestIds || $retesting->count() === 0) {
            return $retesting instanceof TestIds ? Saved::of($this->changed, Paths::none()) : $retesting;
        }

        $retested = Paths::none();

        foreach ($map->files() as $file) {
            $retested = $map->testsCoveringFile($file)->among($retesting)->count() > 0
                ? $retested->with($file)
                : $retested;
        }

        return Saved::of($this->changed, $retested);
    }
}
