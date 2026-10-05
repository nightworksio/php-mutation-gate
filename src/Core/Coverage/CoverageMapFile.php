<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

use stdClass;

/**
 * A coverage map as the gate carries it between jobs: `"format": 1`, compact
 * JSON, gzipped. Every test is listed once, with its seconds where it was
 * timed, and each executable line of each file names its tests by their
 * place in that list: none for a line the run missed. Where the report of
 * the run that measured it states them,
 * `methods` lists each file's executed methods with the lines they span,
 * `{"name", "start", "end"}`; a map without them is whole. A whole map says
 * where it was measured, as `commit` and `dirty` ({@see MeasuredAt}).
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
    /** What a message calls the file. */
    public const string NAMED = 'The coverage map';

    /** A listed test's field that holds its seconds. */
    public const string SECONDS = 'seconds';

    /** The field that lists each file's executed methods. */
    public const string METHODS = 'methods';
    /** The map's name in the directory a job hands it over in. */
    private const string NAME = 'map.json.gz';

    private const int FORMAT = 1;

    /** Why a file cannot be read as a map. */
    private const string UNREADABLE = 'The coverage map is not one this gate writes, so no line of it can be read.';

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

    /** A map as the gate writes it, with where it was measured, which a map of some files alone does not say. */
    public static function encode(CoverageMap $map, MeasuredAt|Unplaced $at): string
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

        return Gzip::pack(JsonText::compact([
            'format' => self::FORMAT,
            ...($at instanceof MeasuredAt ? $at->written() : []),
            'tests' => $tests,
            'files' => $files === [] ? new stdClass() : $files,
            ...($methods === [] ? [] : [self::METHODS => $methods]),
        ]));
    }

    public static function decode(string $bytes): CoverageMap|CannotJudge
    {
        $json = Gzip::unpack($bytes, self::NAMED);
        $file = Node::decode($json instanceof CannotJudge ? '' : $json);

        if (! self::isThisFormat($file)) {
            return CannotJudge::because(self::UNREADABLE);
        }

        return MapFileRead::read($file);
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

}
