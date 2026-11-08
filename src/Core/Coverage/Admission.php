<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_diff_key;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;

use Closure;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What of a coverage map the listed suites admit (ADR-0002, ADR-0005): the
 * map less the tests leaving it, except on the lines of each file a test
 * stays on, as a hold from the holding suites keeps its tests on the lines of
 * what it holds. Each test it keeps on some line, and each it never named,
 * keeps its time, and the tests are placed anew where its lines first name
 * them, as `CoverageMap::of()` places them.
 *
 * @internal
 */
final readonly class Admission
{
    /** @param Closure(Path): (Closure(TestId): bool) $staysOn whether a test leaving stays on the lines of a file */
    private function __construct(private TestIds $leaving, private Closure $staysOn)
    {
    }

    /** @param Closure(Path): (Closure(TestId): bool) $staysOn whether a test leaving stays on the lines of a file */
    public static function of(TestIds $leaving, Closure $staysOn): self
    {
        return new self($leaving, $staysOn);
    }

    /** The map as these suites admit it. */
    public function applied(CoverageMap $map): CoverageMap
    {
        $tests = [...$map->tests()];
        $leaving = [];

        foreach ($tests as $place => $test) {
            if ($this->leaving->has($test)) {
                $leaving[$place] = true;
            }
        }

        $placed = $this->placedAnew($map, $tests, $leaving);
        $admitted = CoverageMap::placed($placed['lines'], ...$this->timed($map, $tests, $leaving, $placed['onLines']));

        foreach ($map->methods() as $file => $methods) {
            $admitted = $admitted->executing($file, ...$methods);
        }

        return $admitted->loading($map->loaded());
    }

    /**
     * The map's lines, each with the tests that stay on it, the tests placed
     * where the lines first name them; and the places in the map of the tests
     * kept on some line.
     *
     * @param  list<TestId>                                      $tests
     * @param  array<int, true>                                  $leaving the places of the tests leaving
     * @return array{lines: LineTests, onLines: array<int, int>}
     */
    private function placedAnew(CoverageMap $map, array $tests, array $leaving): array
    {
        $dropped = $this->dropped($map, $tests, $leaving);
        $first = [];

        foreach ($map->placedLines() as $line) {
            $first += array_flip($this->staying($line, $dropped));
        }

        $anew = array_flip(array_keys($first));
        $placed = [];
        $kept = [];

        foreach ($map->placedLines() as $line) {
            $placed[] = Tally::placing($line->file(), $line->line(), $this->staying($line, $dropped), $anew);
        }

        foreach (array_keys($anew) as $place) {
            $kept[] = $tests[$place];
        }

        return ['lines' => LineTests::placed(TestIds::of(...$kept), ...$placed), 'onLines' => $anew];
    }

    /**
     * The places of the tests that leave the lines of each file: each test
     * leaving that runs a line of it and does not stay on it, asked once for
     * each test and file.
     *
     * @param  list<TestId>                    $tests
     * @param  array<int, true>                $leaving
     * @return array<string, array<int, true>> by the file's path
     */
    private function dropped(CoverageMap $map, array $tests, array $leaving): array
    {
        $dropped = [];

        foreach ($leaving === [] ? [] : $this->running($map) as $file => [$path, $places]) {
            $here = array_keys(array_intersect_key($places, $leaving));
            $leaves = $here === [] ? [] : $this->leaving($path, $here, $tests);
            $dropped += $leaves === [] ? [] : [$file => $leaves];
        }

        return $dropped;
    }

    /**
     * Of the tests leaving that run a line of a file, the places of those
     * that do not stay on it.
     *
     * @param  list<int>        $here
     * @param  list<TestId>     $tests
     * @return array<int, true>
     */
    private function leaving(Path $file, array $here, array $tests): array
    {
        $stays = ($this->staysOn)($file);
        $leaves = [];

        foreach ($here as $place) {
            if (! $stays($tests[$place])) {
                $leaves[$place] = true;
            }
        }

        return $leaves;
    }

    /**
     * Each file of the map, with the places of the tests that run a line of
     * it, by its path.
     *
     * @return array<string, array{Path, array<int, int>}>
     */
    private function running(CoverageMap $map): array
    {
        $running = [];

        foreach ($map->placedLines() as $line) {
            $file = $line->file()->value();
            $running[$file] = array_key_exists($file, $running) ? $running[$file] : [$line->file(), []];
            $running[$file][1] += array_flip([...$line]);
        }

        return $running;
    }

    /**
     * The places of a line's tests that stay on it: each but those that
     * leave its file's lines.
     *
     * @param  array<string, array<int, true>> $dropped
     * @return list<int>
     */
    private function staying(PlacedLine $line, array $dropped): array
    {
        $places = [...$line];
        $file = $line->file()->value();

        return array_key_exists($file, $dropped)
            ? array_keys(array_diff_key(array_flip($places), $dropped[$file]))
            : $places;
    }

    /**
     * How long each test that stays took, where the map timed it: a test not
     * leaving, or one kept on some line.
     *
     * @param  list<TestId>     $tests
     * @param  array<int, true> $leaving
     * @param  array<int, int>  $onLines the places of the tests kept on some line
     * @return list<TimedTest>
     */
    private function timed(CoverageMap $map, array $tests, array $leaving, array $onLines): array
    {
        $timed = [];

        foreach (array_diff_key($tests, array_diff_key($leaving, $onLines)) as $test) {
            $duration = $map->durationOf($test);

            if ($duration instanceof Seconds) {
                $timed[] = TimedTest::of($test->value(), $duration->seconds());
            }
        }

        return $timed;
    }
}
