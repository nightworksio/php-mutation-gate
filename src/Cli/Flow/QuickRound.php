<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
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
        return new self($changes->paths());
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
    public function saved(CoverageEntries|CannotJudge|NotGiven $entries, CoverageMap $map): Saved|CannotJudge
    {
        if (! $entries instanceof CoverageEntries) {
            return $entries instanceof CannotJudge ? $entries : Saved::of($this->changed, Paths::none());
        }

        $tests = [];

        foreach ($this->changed as $path) {
            $tests = $entries->testFiles()->has($path) ? [...$tests, $path] : $tests;
        }

        $retesting = $tests === [] ? TestIds::none() : $entries->testsIn(Paths::of(...$tests), $map);

        return $retesting instanceof TestIds
            ? Saved::of($this->changed, $this->retested($retesting, $map))
            : $retesting;
    }

    /** The PHP files the round changed that are outside the suite's tests. */
    public function sourcesChanged(CoverageEntries $entries, Suite $suite): Paths
    {
        $outside = [];

        foreach ($suite->outside() as $file) {
            $outside[] = $file->path();
        }

        $outsidePaths = Paths::of(...$outside);
        $sources = [];

        foreach ($this->changed as $path) {
            $isSource = $path->isPhp() && $outsidePaths->has($path) && ! $entries->testFiles()->has($path);
            $sources = $isSource ? [...$sources, $path] : $sources;
        }

        return Paths::of(...$sources);
    }

    /**
     * The test files the round changed.
     *
     * @return list<Path>
     */
    public function testsChanged(CoverageEntries $entries): array
    {
        $tests = [];

        foreach ($this->changed as $path) {
            $tests = $path->isPhp() && $entries->testFiles()->has($path) ? [...$tests, $path] : $tests;
        }

        return $tests;
    }

    /** The files a map shows these tests running; none where there are no tests. */
    private function retested(TestIds $retesting, CoverageMap $map): Paths
    {
        $retested = Paths::none();

        foreach ($retesting->count() === 0 ? [] : $map->files() as $file) {
            $retested = $map->testsCoveringFile($file)->among($retesting)->count() > 0
                ? $retested->with($file)
                : $retested;
        }

        return $retested;
    }
}
