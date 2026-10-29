<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_map;
use function array_pop;
use function count;

use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Core\Control\MutantParts;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The trials of a mutant of a line that is not executable, in the order
 * they run (ADR-0008): its test files in one trial where their tests fit in
 * one run under `timeouts.most`, and in parts that each fit where they do
 * not, nearest the mutant's file first, then the fastest (see MutantParts),
 * each file whole in the first part that holds any of its tests, and the
 * files that hold no test the map times in the last. Each is allowed the
 * standard limit of the own time of the tests its files hold, kept between
 * the bounds.
 */
final readonly class TrialParts
{
    private function __construct(private TestFiles $tests, private CoverageMap $map, private LimitBounds $bounds)
    {
    }

    public static function under(TestFiles $tests, CoverageMap $map, LimitBounds $bounds): self
    {
        return new self($tests, $map, $bounds);
    }

    /**
     * The trials of the mutant whose mutated copy of a file is kept at a
     * path, by the tests in these files.
     *
     * @return non-empty-list<TrialRun>
     */
    public function of(Paths $files, Path $original, string $copy): array
    {
        $parts = MutantParts::under($this->map, $this->bounds);
        $sets = $parts->overflow($this->tests->holding($files, $this->map))
            ? $this->split($parts, $files, $original)
            : [$files];

        return array_map(
            fn(Paths $set): TrialRun => TrialRun::of($set, $original, $copy, $this->limitOf($set)),
            $sets,
        );
    }

    /**
     * How long a run of the tests in some files is allowed: the standard
     * mutant limit of their own time, as the map timed them, kept between
     * the bounds.
     */
    private function limitOf(Paths $files): Seconds
    {
        $tests = $this->tests->holding($files, $this->map);

        return MutantLimit::standard()->of(OwnTime::of($this->map, $tests), $this->bounds);
    }

    /**
     * These files in parts that each fit, each file in the first part that
     * holds any of its tests, and those that hold no test the map times
     * added to the last.
     *
     * @return non-empty-list<Paths>
     */
    private function split(MutantParts $parts, Paths $files, Path $original): array
    {
        $places = TestPlaces::none();

        foreach ($files as $file) {
            $places = $places->placing($file, $this->tests->holding(Paths::of($file), $this->map));
        }

        $sets = [];
        $placed = Paths::none();

        foreach ($parts->of($original, $this->tests->holding($files, $this->map), $places) as $part) {
            $set = $this->filesOf($part, $places)->without($placed);
            $placed = $placed->and($set);
            $sets = count($set) > 0 ? [...$sets, $set] : $sets;
        }

        $last = array_pop($sets);

        return $last instanceof Paths ? [...$sets, $last->and($files->without($placed))] : [$files];
    }

    /** The files that hold the tests of a part, in the order of its tests. */
    private function filesOf(RunTests $part, TestPlaces $places): Paths
    {
        $files = Paths::none();

        foreach ($places->holding($part->tests()) as [$file]) {
            $files = $files->with($file);
        }

        return $files;
    }
}
