<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_filter;
use function array_values;
use function file;
use function glob;

use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_starts_with;

/**
 * How the tests ran in a shard's stream parents (ADR-0135), from every
 * `recorded-*.jsonl` of a directory: each test's end, each line pcov
 * reported in each file of the project, and whether every stream ended
 * each of its tests, which a stream that stopped short leaves unsaid.
 *
 * @internal
 */
final readonly class RecordedSuite
{
    private const string RECORDED = '%s/recorded-*.jsonl';

    /** The suite recorded in a directory; none where its streams recorded nothing. */
    public static function in(string $directory, Root $root): StreamedSuite|NotGiven
    {
        $files = glob(sprintf(self::RECORDED, $directory));

        if ($files === false || $files === []) {
            return NotGiven::value();
        }

        $suite = StreamedSuite::none();

        foreach ($files as $file) {
            $texts = file($file);
            $suite = $suite->and(self::of($texts === false ? [] : $texts, $root));
        }

        return $suite;
    }

    /** @param list<string> $texts one stream's lines */
    private static function of(array $texts, Root $root): StreamedSuite
    {
        $finished = false;
        $tests = [];
        $reported = [];

        foreach ($texts as $text) {
            $line = Node::decode($text);
            $kind = self::kindOf($line);

            if ($kind === StreamLine::FINISHED) {
                $finished = true;
                $reported = self::inside($line->field('l'), $root);
            }

            foreach ($kind === StreamLine::COVERED ? self::ended($line) : [] as $test => $ended) {
                $tests[$test] = $ended;
            }
        }

        return StreamedSuite::of($finished, $tests, $reported);
    }

    /** What a line is; nothing where it does not say. */
    private static function kindOf(Node $line): string
    {
        try {
            return $line->field(StreamLine::KIND)->text();
        } catch (NotInShape) {
            return '';
        }
    }

    /** @return array<string, TestEnded> the test a covered line ended, by its name; none where it holds none */
    private static function ended(Node $line): array
    {
        try {
            return [$line->field('t')->text() => new TestEnded(
                passed: $line->field('p')->boolean(),
                troubled: $line->field('i')->boolean(),
                own: $line->field(StreamLine::OWN)->boolean(),
                seconds: $line->field('s')->number(),
            )];
        } catch (NotInShape) {
            return [];
        }
    }

    /** @return array<string, list<int>> each file of the project's lines, by its path from the root */
    private static function inside(Node $files, Root $root): array
    {
        $inside = [];

        foreach (self::entriesOf($files) as $file => $lines) {
            $path = sprintf('%s', $file);

            if (str_starts_with($path, $root->value())) {
                $inside[$root->relative($path)->value()] = self::numbered($lines);
            }
        }

        return $inside;
    }

    /** @return array<array-key, Node> each file's lines; none where the line holds no map of them */
    private static function entriesOf(Node $files): array
    {
        try {
            return $files->entries();
        } catch (NotInShape) {
            return [];
        }
    }

    /** @return list<int> the numbers of the lines a file's entry holds; none where it holds anything else */
    private static function numbered(Node $lines): array
    {
        try {
            return array_values(array_filter($lines->integers(), static fn(int $number): bool => $number > 0));
        } catch (NotInShape) {
            return [];
        }
    }
}
