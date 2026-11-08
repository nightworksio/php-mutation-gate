<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_diff_key;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What of a coverage map the listed suites admit (ADR-0002, ADR-0005): the
 * map less the tests leaving it, except on the lines of each file a test
 * stays on, as a hold from the holding suites keeps its tests on the lines of
 * what it holds. Each test it keeps on some line, and each it never named,
 * keeps its time, and the tests are placed anew where its lines first name
 * them, as `CoverageMap::of()` places them. The gate decides it from the
 * map's outline; the gate or an engine applies it (ADR-0119).
 *
 * @internal
 */
final readonly class Admission
{
    /** @param array<string, array<string, true>> $staying the paths of the files each test leaving stays on, by id */
    private function __construct(private array $staying)
    {
    }

    /** @param array<string, array<string, true>> $staying the paths of the files each test leaving stays on, by id */
    public static function of(array $staying): self
    {
        return new self($staying);
    }

    /**
     * The tests leaving, each with the paths of the files it stays on.
     *
     * @return array<string, array<string, true>> by the test's id
     */
    public function staying(): array
    {
        return $this->staying;
    }

    /** A map's outline, as this admits the map. */
    public function outlined(MapOutline $outline): MapOutline
    {
        return $outline->leaving($this->staying);
    }

    /** The map as these suites admit it. */
    public function applied(CoverageMap $map): CoverageMap
    {
        $tests = [...$map->tests()];
        $leaving = [];

        foreach ($tests as $place => $test) {
            if (array_key_exists($test->value(), $this->staying)) {
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
            $first += array_flip($this->stayingOn($line, $dropped));
        }

        $anew = array_flip(array_keys($first));
        $placed = [];
        $kept = [];

        foreach ($map->placedLines() as $line) {
            $placed[] = Tally::placing($line->file(), $line->line(), $this->stayingOn($line, $dropped), $anew);
        }

        foreach (array_keys($anew) as $place) {
            $kept[] = $tests[$place];
        }

        return ['lines' => LineTests::placed(TestIds::of(...$kept), ...$placed), 'onLines' => $anew];
    }

    /**
     * The places of the tests that leave the lines of each file: each test
     * leaving that runs a line of it and does not stay on it.
     *
     * @param  list<TestId>                    $tests
     * @param  array<int, true>                $leaving
     * @return array<string, array<int, true>> by the file's path
     */
    private function dropped(CoverageMap $map, array $tests, array $leaving): array
    {
        $dropped = [];

        foreach ($leaving === [] ? [] : $this->running($map) as $file => $places) {
            foreach (array_keys(array_intersect_key($places, $leaving)) as $place) {
                if (! array_key_exists($file, $this->staying[$tests[$place]->value()])) {
                    $dropped[$file][$place] = true;
                }
            }
        }

        return $dropped;
    }

    /**
     * The places of the tests that run a line of each file of the map, by its path.
     *
     * @return array<string, array<int, int>>
     */
    private function running(CoverageMap $map): array
    {
        $running = [];

        foreach ($map->placedLines() as $line) {
            $file = $line->file()->value();
            $running[$file] = array_key_exists($file, $running) ? $running[$file] : [];
            $running[$file] += array_flip([...$line]);
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
    private function stayingOn(PlacedLine $line, array $dropped): array
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
