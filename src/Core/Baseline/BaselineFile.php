<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

use function implode;
use function intdiv;
use function json_encode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Score\Floor;

use function rtrim;
use function sprintf;

/**
 * The baseline as it is committed: format 1, and under `trees` one key per
 * tree in byte order, each floor on a line of its own, so a moved floor is a
 * one-line diff. A lowered floor carries `lowered`, with the floor it went
 * down from and why.
 *
 * ```json
 * {
 *     "format": 1,
 *     "trees": {
 *         "app/Domain": { "floor": 100 },
 *         "app/Legacy": {
 *             "floor": 61.2,
 *             "lowered": { "from": 64.5, "reason": "The export feature and its tests were removed together" }
 *         }
 *     }
 * }
 * ```
 */
final readonly class BaselineFile
{
    private const int FORMAT = 1;

    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private const int HUNDREDTHS_PER_PERCENT = 100;

    /** The highest floor there is, in percent. */
    private const int HIGHEST = 100;

    private const string FLOOR = 'floor';

    private const string LOWERED = 'lowered';

    private const string PLAIN = '        %s: { "floor": %s }';

    private const string LOWERED_ENTRY = <<<'JSON'
                %s: {
                    "floor": %s,
                    "lowered": { "from": %s, "reason": %s }
                }
        JSON;

    private const string FILE = <<<'JSON'
        {
            "format": %d,
            "trees": {%s}
        }

        JSON;

    public static function encode(Baseline $baseline): string
    {
        $entries = [];

        foreach ($baseline as $entry) {
            $entries[] = self::entry($entry);
        }

        $trees = $entries === [] ? '' : sprintf("\n%s\n    ", implode(",\n", $entries));

        return sprintf(self::FILE, self::FORMAT, $trees);
    }

    /** The baseline a file holds, or why it cannot be read, naming where it went wrong. */
    public static function decode(string $json, Path $file): Baseline|CannotJudge
    {
        try {
            return self::baselineIn(Node::decode($json));
        } catch (NotInShape $refused) {
            return CannotJudge::because(sprintf(
                'The baseline %s cannot be read: %s Fix it, or run mutation-gate baseline --write.',
                $file->value(),
                $refused->getMessage(),
            ));
        }
    }

    /** A floor as the file writes it: a whole number where it is one, and otherwise no trailing zero. */
    public static function number(Floor $floor): string
    {
        $hundredths = $floor->hundredths();
        $fraction = $hundredths % self::HUNDREDTHS_PER_PERCENT;
        $whole = intdiv($hundredths, self::HUNDREDTHS_PER_PERCENT);

        return $fraction === 0 ? sprintf('%d', $whole) : rtrim(sprintf('%d.%02d', $whole, $fraction), '0');
    }

    private static function entry(Entry $entry): string
    {
        $lowered = $entry->lowering();
        $tree = self::text($entry->tree()->value());

        return $lowered instanceof Lowered
            ? sprintf(
                self::LOWERED_ENTRY,
                $tree,
                self::number($entry->floor()),
                self::number($lowered->floor()),
                self::text($lowered->reason()),
            )
            : sprintf(self::PLAIN, $tree, self::number($entry->floor()));
    }

    private static function text(string $text): string
    {
        return json_encode($text, self::FLAGS);
    }

    /** @throws NotInShape */
    private static function baselineIn(Node $file): Baseline
    {
        if ($file->field('format')->integer() !== self::FORMAT) {
            throw NotInShape::at($file->field('format')->at(), sprintf('format %d', self::FORMAT));
        }

        $entries = [];

        foreach ($file->field('trees')->entries() as $tree => $entry) {
            $entries[] = self::entryIn(Path::of($tree), $entry);
        }

        return Baseline::of(...$entries);
    }

    /** @throws NotInShape */
    private static function entryIn(Path $tree, Node $entry): Entry
    {
        $read = Entry::of($tree, self::floorIn($entry->field(self::FLOOR)));
        $lowered = $entry->field(self::LOWERED);

        return $lowered->isPresent()
            ? $read->lowered(Lowered::from(self::floorIn($lowered->field('from')), $lowered->field('reason')->text()))
            : $read;
    }

    /** @throws NotInShape */
    private static function floorIn(Node $floor): Floor
    {
        $percent = $floor->number();

        return $percent >= 0 && $percent <= self::HIGHEST
            ? Floor::of($percent)
            : throw NotInShape::at($floor->at(), 'a floor from 0 to 100');
    }
}
