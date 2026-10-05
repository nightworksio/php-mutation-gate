<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_key_exists;
use function array_map;
use function array_merge;
use function count;
use function intval;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\WholeNumber;

use function strval;

/**
 * A coverage map file, decoded, read into a map as its file holds it: each
 * listed test once, each line as the places of its tests in that list, each
 * well-formed entry kept and anything else dropped, never repaired (see
 * CoverageMapFile).
 *
 * @internal the reading half of CoverageMapFile
 */
final readonly class MapFileRead
{
    /** The map a decoded file of this format holds. */
    public static function read(Node $file): CoverageMap
    {
        [$tests, $places, $timed] = self::listed(self::itemsOf($file->field('tests')));
        $lines = [];

        foreach (self::entriesOf($file->field('files')) as $path => $listed) {
            $lines[] = self::linesIn(Path::of(strval($path)), $listed, $places);
        }

        $map = CoverageMap::placed(LineTests::placed(TestIds::of(...$tests), ...array_merge(...$lines)), ...$timed);

        foreach (self::entriesOf($file->field(CoverageMapFile::METHODS)) as $path => $methods) {
            $listed = array_merge(...array_map(self::methodIn(...), self::itemsOf($methods)));
            $map = $map->executing(Path::of(strval($path)), ...$listed);
        }

        return $map;
    }

    /**
     * The listed tests, each once, in the order first listed; where each
     * place of the list stands in it, none for an entry that is not a test;
     * and each timed test.
     *
     * @param  list<Node>                                               $listed
     * @return array{list<TestId>, array<int, int<0, max>>, list<TimedTest>}
     */
    private static function listed(array $listed): array
    {
        $tests = [];
        $byId = [];
        $places = [];
        $timed = [];

        foreach ($listed as $place => $entry) {
            foreach (self::testIn($entry) as $test) {
                $id = $test instanceof TimedTest ? $test->test() : $test;

                if (! array_key_exists($id->value(), $byId)) {
                    $byId[$id->value()] = count($tests);
                    $tests[] = $id;
                }

                $places[$place] = $byId[$id->value()];

                if ($test instanceof TimedTest) {
                    $timed[] = $test;
                }
            }
        }

        return [$tests, $places, $timed];
    }

    /**
     * A file's well-formed lines, each with where its tests stand in the
     * list: none for a line no test ran.
     *
     * @param  array<int, int<0, max>> $places
     * @return list<PlacedLine>
     */
    private static function linesIn(Path $path, Node $lines, array $places): array
    {
        $read = [];

        foreach (self::entriesOf($lines) as $line => $at) {
            $by = self::placesIn($at, $places);
            $number = strval($line);

            if (! $by instanceof NotGiven && WholeNumber::isPositive($number)) {
                $read[] = PlacedLine::of($path, intval($number), ...$by);
            }
        }

        return $read;
    }

    /**
     * Where the tests a line lists stand in the list of tests; none where the
     * line is not a list of places of listed tests.
     *
     * @param array<int, int<0, max>> $places
     *
     * @return list<int<0, max>>|NotGiven
     */
    private static function placesIn(Node $at, array $places): array|NotGiven
    {
        try {
            $listed = $at->integers();
        } catch (NotInShape) {
            return NotGiven::value();
        }

        $by = [];

        foreach ($listed as $place) {
            if (! array_key_exists($place, $places)) {
                return NotGiven::value();
            }

            $by[] = $places[$place];
        }

        return $by;
    }

    /**
     * A listed method, where it names itself and spans lines that begin
     * after the file does and end no sooner than they begin; none otherwise.
     *
     * @return list<ExecutedMethod>
     */
    private static function methodIn(Node $method): array
    {
        try {
            $name = $method->field('name')->text();
            $start = $method->field('start')->integer();
            $end = $method->field('end')->integer();
        } catch (NotInShape) {
            return [];
        }

        return $name !== '' && $start >= 1 && $end >= $start ? [ExecutedMethod::of($name, $start, $end)] : [];
    }

    /**
     * A listed test, with how long it took where it was timed; none where the
     * entry is not a test, which leaves its place empty.
     *
     * @return list<TimedTest|TestId>
     */
    private static function testIn(Node $test): array
    {
        try {
            $id = $test->field('id')->text();
            $seconds = $test->field(CoverageMapFile::SECONDS);

            return [$seconds->isPresent() ? TimedTest::of($id, self::secondsIn($seconds)) : TestId::of($id)];
        } catch (NotInShape) {
            return [];
        }
    }

    /** @throws NotInShape */
    private static function secondsIn(Node $seconds): float
    {
        $value = $seconds->number();

        return $value >= 0.0 ? $value : throw NotInShape::at($seconds->at(), 'a duration');
    }

    /** @return list<Node> */
    private static function itemsOf(Node $list): array
    {
        try {
            return $list->items();
        } catch (NotInShape) {
            return [];
        }
    }

    /** @return array<array-key, Node> each entry, by its key, which PHP keys as a number where it reads as one */
    private static function entriesOf(Node $map): array
    {
        try {
            return $map->entries();
        } catch (NotInShape) {
            return [];
        }
    }
}
