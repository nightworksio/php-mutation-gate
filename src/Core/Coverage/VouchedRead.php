<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_key_exists;
use function array_map;
use function array_merge;
use function json_decode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

/**
 * A coverage map's text an engine vouched for, read into a map without
 * asking each entry its shape (ADR-0118): the engine vouched that every test is
 * listed once with an id and a duration of nought or more where it has one,
 * every line is a whole number of one or more naming listed tests by their
 * place, every method is named and spans its lines, and every test a process
 * loaded is named by its place with its packages and whether it started
 * processes, so the careful reading
 * (see MapFileRead) would keep every entry and place each test where the file
 * does. The map is built by the same calls in the same order, so it is the map
 * the careful reading builds.
 *
 * @internal the reading of CoverageMapFile for a vouched text
 *
 * @phpstan-type Vouched array{
 *     tests: list<array{id: string, seconds?: int|float}>,
 *     files: array<array-key, array<int, list<int<0, max>>>>,
 *     methods?: array<array-key, list<array{name: string, start: int, end: int}>>,
 *     loads?: list<list<array{int<0, max>, list<string>, bool}>>,
 * }
 */
final readonly class VouchedRead
{
    /** The map a text of this format holds, its shape vouched for. */
    public static function read(string $json): CoverageMap
    {
        /** @var Vouched $vouched */
        $vouched = json_decode($json, associative: true);
        $tests = [];
        $timed = [];

        foreach ($vouched['tests'] as $test) {
            $tests[] = TestId::of($test['id']);

            if (array_key_exists(CoverageMapFile::SECONDS, $test)) {
                $timed[] = TimedTest::of($test['id'], $test[CoverageMapFile::SECONDS]);
            }
        }

        $lines = [];

        foreach ($vouched['files'] as $path => $listed) {
            $lines[] = self::linesIn(Path::of(sprintf('%s', $path)), $listed);
        }

        $map = CoverageMap::placed(LineTests::placed(TestIds::of(...$tests), ...array_merge(...$lines)), ...$timed);
        $methods = array_key_exists(CoverageMapFile::METHODS, $vouched) ? $vouched[CoverageMapFile::METHODS] : [];

        foreach ($methods as $path => $executed) {
            $listed = array_map(
                static fn(array $method): ExecutedMethod => ExecutedMethod::of(
                    $method['name'],
                    $method['start'],
                    $method['end'],
                ),
                $executed,
            );
            $map = $map->executing(Path::of(sprintf('%s', $path)), ...$listed);
        }

        $loads = array_key_exists(CoverageMapFile::LOADS, $vouched) ? $vouched[CoverageMapFile::LOADS] : [];

        return $map->loading(self::loadedIn($loads, $tests));
    }

    /**
     * What each process's tests loaded, each test read by its place in the map's tests.
     *
     * @param list<list<array{int<0, max>, list<string>, bool}>> $loads
     * @param list<TestId>                                       $tests
     */
    private static function loadedIn(array $loads, array $tests): LoadedPackages
    {
        $processes = [];

        foreach ($loads as $process) {
            $read = [];

            foreach ($process as [$place, $packages, $starts]) {
                $load = TestLoad::of($tests[$place]->value(), ...$packages);
                $read[] = $starts ? $load->startingProcesses() : $load;
            }

            $processes[] = ProcessLoads::of(...$read);
        }

        return $processes === [] ? LoadedPackages::unrecorded() : LoadedPackages::of(...$processes);
    }

    /**
     * A file's lines, each with where its tests stand in the list.
     *
     * @param  array<int, list<int<0, max>>> $listed
     * @return list<PlacedLine>
     */
    private static function linesIn(Path $path, array $listed): array
    {
        $lines = [];

        foreach ($listed as $line => $places) {
            $lines[] = PlacedLine::of($path, $line, ...$places);
        }

        return $lines;
    }
}
