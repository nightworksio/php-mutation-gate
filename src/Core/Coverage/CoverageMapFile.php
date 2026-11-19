<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use Closure;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

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
 * repairing it. A file that is not such a map cannot be judged by. Where an
 * engine vouches that a text holds nothing to drop, it is built straight from
 * its decoded text, into the same map (ADR-0118).
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

    /** The field that holds what each process's tests loaded (ADR-0115), where the run recorded it. */
    public const string LOADS = 'loads';

    /** The format a map's file is written in. */
    public const int FORMAT = 1;

    /** The map's name in the directory a job hands it over in. */
    private const string NAME = 'map.json.gz';

    /** Why a map is not kept: it is past a store's limits, packed or as text. */
    private const string OVER = 'The coverage map is %d bytes packed and %d bytes as text, over what a store keeps.';

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

    /**
     * A map as the gate writes it, packed at this level, with where it was
     * measured, which a map of some files alone does not say, and each test
     * file's entry key where the map is one a store keeps. A map's tables, read once, write it and
     * each of its files alone as its map would.
     */
    public static function encode(
        CoverageMap|MapText $map,
        MeasuredAt|Unplaced $at,
        EntryKeys|NotGiven $keys = new NotGiven(),
        int $level = Gzip::LEVEL,
    ): string {
        return Gzip::packAt(($map instanceof MapText ? $map : MapText::of($map))->written($at, $keys), $level);
    }

    /**
     * A map a store keeps, packed within its limits; or why it is not kept:
     * it is past either.
     */
    public static function keeping(KeptMap $kept, MapLimits $limits, int $level = Gzip::LEVEL): string|CannotJudge
    {
        $text = MapText::of($kept->map())->written($kept->measuredAt(), $kept->keys());
        $bytes = Gzip::packAt($text, $level);

        return $limits->admits($text, $bytes)
            ? $bytes
            : CannotJudge::because(sprintf(self::OVER, Bytes::length($bytes), Bytes::length($text)));
    }

    /**
     * A map a store kept, read within its limits as data, never run: the map,
     * where it was measured, and each test file's entry key, each malformed
     * entry dropped; or why it cannot be read. A text the engine asked vouches
     * for is built straight from its decoded text.
     *
     * @param (Closure(string): MapVouch)|NotGiven $vouching
     */
    public static function kept(
        string $bytes,
        MapLimits $limits,
        Closure|NotGiven $vouching = new NotGiven(),
    ): KeptMap|CannotJudge {
        $json = Gzip::unpackAtMost($bytes, self::NAMED, $limits->unpacked());

        if (! is_string($json)) {
            return $json instanceof TooLarge ? CannotJudge::because($json->why()) : $json;
        }

        $file = Node::decode($json);

        return self::isThisFormat($file)
            ? KeptMap::of(
                self::vouchOf($json, $vouching) === MapVouch::Vouched
                    ? VouchedRead::read($json)
                    : MapFileRead::read($file),
                MeasuredAt::readIn($file),
                EntryKeys::readIn($file),
            )
            : CannotJudge::because(self::UNREADABLE);
    }

    /**
     * The map some bytes hold, read within these limits; or why none is read.
     * A text the engine asked vouches for is built straight from its decoded
     * text.
     *
     * @param (Closure(string): MapVouch)|NotGiven $vouching
     */
    public static function decode(
        string $bytes,
        HandoffLimits $limits,
        Closure|NotGiven $vouching = new NotGiven(),
    ): CoverageMap|CannotJudge {
        $json = $limits->inflated($bytes);

        if ($json instanceof TooLarge) {
            return CannotJudge::because($json->why());
        }

        if (is_string($json) && self::vouchOf($json, $vouching) === MapVouch::Vouched) {
            return VouchedRead::read($json);
        }

        $file = $json instanceof CannotJudge ? $json : Node::decode($json);

        return $file instanceof Node && self::isThisFormat($file)
            ? MapFileRead::read($file)
            : CannotJudge::because(self::UNREADABLE);
    }

    /**
     * Whether a map's text is vouched for: by the engine asked, where one is;
     * by none otherwise. A vouched text is a map of this format.
     *
     * @param (Closure(string): MapVouch)|NotGiven $vouching
     */
    private static function vouchOf(string $json, Closure|NotGiven $vouching): MapVouch
    {
        return $vouching instanceof Closure ? $vouching($json) : MapVouch::Unvouched;
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
