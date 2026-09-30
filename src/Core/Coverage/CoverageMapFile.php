<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function count;
use function intval;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_match;
use function sprintf;

use stdClass;

use function strval;

/**
 * A coverage map as the gate carries it between jobs: `"format": 1`, compact
 * JSON, gzipped. Every test is listed once, with its seconds where it was
 * timed, and each covered line of each file names its tests by their place
 * in that list. Where the report of the run that measured it states them,
 * `methods` lists each file's executed methods with the lines they span,
 * `{"name", "start", "end"}`; a map without them is whole.
 *
 * It is data, never code: a runner's own map may be PHP that reading runs,
 * and is read only by the job that wrote it.
 *
 * Reading keeps each well-formed entry and drops anything else, never
 * repairing it. A file that is not such a map cannot be judged by.
 *
 * @internal the shape of the coverage file the plan hands each shard
 *
 * @phpstan-type TestRecord array{id: string, seconds?: float}
 * @phpstan-type MethodRecord array{name: string, start: int, end: int}
 */
final readonly class CoverageMapFile
{
    /** The map's name in the directory a job hands it over in. */
    private const string NAME = 'map.json.gz';

    private const int FORMAT = 1;

    /** Why a file cannot be read as a map. */
    private const string UNREADABLE = 'The coverage map is not one this gate writes, so no line of it can be read.';

    /** A line's number, as a key of the file. */
    private const string LINE = '/^[1-9]\d*$/D';

    private const string SECONDS = 'seconds';

    private const string METHODS = 'methods';

    /** Why a job finds no map to read: it reads only the gate's own, never a map a runner wrote. */
    private const string MISSING
        = 'The gate wrote no coverage map at %s, and reads no runner\'s map another job wrote.';

    /** Where the map stands in the directory a job hands it over in. */
    public static function in(Path $directory): Path
    {
        return Path::of(sprintf('%s/%s', $directory->value(), self::NAME));
    }

    /** Why there is no map to read at a file: the gate wrote none there. */
    public static function missingAt(string $file): CannotJudge
    {
        return CannotJudge::because(sprintf(self::MISSING, $file));
    }

    public static function encode(CoverageMap $map): string
    {
        $places = [];
        $tests = [];

        foreach ($map->tests() as $test) {
            $places[$test->value()] = count($tests);
            $tests[] = self::test($test, $map);
        }

        $files = [];

        foreach ($map->lines() as $line) {
            $files[$line->file()->value()][$line->line()] = array_map(
                static fn(string $test): int => $places[$test],
                [...$line],
            );
        }

        $methods = self::methodsOf($map);

        return Gzip::pack(Json::compact([
            'format' => self::FORMAT,
            'tests' => $tests,
            'files' => $files === [] ? new stdClass() : $files,
            ...($methods === [] ? [] : [self::METHODS => $methods]),
        ]));
    }

    public static function decode(string $bytes): CoverageMap|CannotJudge
    {
        $json = Gzip::unpack($bytes, 'The coverage map');
        $file = Node::decode($json instanceof CannotJudge ? '' : $json);

        if (! self::isThisFormat($file)) {
            return CannotJudge::because(self::UNREADABLE);
        }

        $tests = array_map(self::testIn(...), self::itemsOf($file->field('tests')));
        $covered = [];

        foreach (self::entriesOf($file->field('files')) as $path => $lines) {
            $covered[] = self::coveredIn(Path::of(strval($path)), $lines, $tests);
        }

        $map = CoverageMap::of(...array_merge(...$covered))->timedEach(...array_filter(
            array_merge(...$tests),
            static fn(TimedTest|TestId $test): bool => $test instanceof TimedTest,
        ));

        foreach (self::entriesOf($file->field(self::METHODS)) as $path => $methods) {
            $listed = array_merge(...array_map(self::methodIn(...), self::itemsOf($methods)));
            $map = $map->executing(Path::of(strval($path)), ...$listed);
        }

        return $map;
    }

    /** @return array<string, list<MethodRecord>> each file's executed methods, by path */
    private static function methodsOf(CoverageMap $map): array
    {
        $methods = [];

        foreach ($map->methods() as $file => $executed) {
            foreach ($executed as $method) {
                $methods[$file->value()][] = [
                    'name' => $method->name(),
                    'start' => $method->first()->number(),
                    'end' => $method->last()->number(),
                ];
            }
        }

        return $methods;
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

    /** @return TestRecord */
    private static function test(TestId $test, CoverageMap $map): array
    {
        $seconds = $map->durationOf($test);

        return $seconds instanceof Seconds
            ? ['id' => $test->value(), self::SECONDS => $seconds->seconds()]
            : ['id' => $test->value()];
    }

    private static function isThisFormat(Node $file): bool
    {
        try {
            return $file->field('format')->integer() === self::FORMAT;
        } catch (NotInShape) {
            return false;
        }
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
            $seconds = $test->field(self::SECONDS);

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

    /**
     * Every well-formed covered line of a file, with the ids of the tests
     * that ran it.
     *
     * @param  list<list<TimedTest|TestId>> $tests each listed test at its place, or none where it was not a test
     * @return list<CoveredLine>
     */
    private static function coveredIn(Path $file, Node $lines, array $tests): array
    {
        $covered = [];

        foreach (self::entriesOf($lines) as $line => $places) {
            $covered[] = self::lineIn($file, $line, $places, $tests);
        }

        return array_merge(...$covered);
    }

    /**
     * @param  list<list<TimedTest|TestId>> $tests
     * @return list<CoveredLine>            the line, or none where it is not well formed
     */
    private static function lineIn(Path $file, int|string $line, Node $places, array $tests): array
    {
        $number = strval($line);

        try {
            $ids = array_map(static fn(int $place): string => self::idAt($place, $tests, $places), $places->integers());
        } catch (NotInShape) {
            return [];
        }

        return preg_match(self::LINE, $number) === 1 ? [CoveredLine::of($file, intval($number), ...$ids)] : [];
    }

    /**
     * The id of the test at a place in the list.
     *
     * @param list<list<TimedTest|TestId>> $tests
     *
     * @throws NotInShape
     */
    private static function idAt(int $place, array $tests, Node $at): string
    {
        $test = array_key_exists($place, $tests) ? $tests[$place] : [];

        return match (true) {
            $test === [] => throw NotInShape::at($at->at(), sprintf('the place of a listed test, not %d', $place)),
            $test[0] instanceof TimedTest => $test[0]->test()->value(),
            default => $test[0]->value(),
        };
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

    /** @return array<string, Node> */
    private static function entriesOf(Node $map): array
    {
        try {
            return $map->entries();
        } catch (NotInShape) {
            return [];
        }
    }
}
