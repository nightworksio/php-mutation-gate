<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_any;
use function array_key_exists;
use function array_slice;
use function count;
use function explode;

use const FILE_APPEND;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_file;
use function is_string;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use function mb_substr_count;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\LineSpan;
use NightWorksIO\MutationGate\Core\Mutant\MadeFile;
use NightWorksIO\MutationGate\Core\Mutant\MadeLines;
use NightWorksIO\MutationGate\Core\NotGiven;

use function realpath;

use SplFileInfo;

use function sprintf;
use function strval;
use function unlink;

/**
 * The lines of each file a part of a cut run's tests makes mutants on
 * (ADR-0193): those its tests cover, and those no test covers. A mutant
 * that touches none of them is covered by other parts' tests alone, so this
 * part would only leave it to them; its making and leaving are spared. Each
 * file made on some lines alone is listed beside the run's results, so the
 * verdict knows which mutants each part made.
 *
 * @internal
 */
final readonly class PartLines
{
    /** The file beside a run's results that lists each file it made on some lines alone, one a line. */
    private const string LISTED = '%s.made';

    /** How a file's line is written: on one line, a path spelt as it is, a byte that is not UTF-8 replaced. */
    private const int LINE = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct(private TestParts $parts, private string $results)
    {
    }

    /**
     * The lines of this run's part, its list beside the run's results begun
     * empty; none where the run splits no streams, is not cut, records a map
     * or makes every mutant by `splitStream.reachableOnly`.
     */
    public static function ofRun(): self|NotGiven
    {
        $settings = StreamSettings::fromEnvironment();
        $results = getenv(GateVariable::Results->value);

        $cut = ! $settings->part()->isWhole() && $settings->recording() === '' && $settings->pace()->reachableOnly;

        if (! Streams::areOn() || ! $cut || ! is_string($results)) {
            return NotGiven::value();
        }

        return self::cutBy(Streams::parts($settings), $results);
    }

    /** The lines of this part of a run's cut tests, listed beside these results, the list begun empty. */
    public static function cutBy(TestParts $parts, string $results): self
    {
        file_put_contents(sprintf(self::LISTED, $results), '');

        return new self($parts, $results);
    }

    /**
     * The lines of a file whose mutants the part makes: every line where
     * that is all of them, and none where it is none; listed beside the
     * run's results where it is some.
     *
     * @param  array<int, array<int, string>> $covered the tests that cover each line of the file, by the line
     * @return list<int>|false
     */
    public function of(SplFileInfo $file, array $covered): array|false
    {
        $contents = file_get_contents(strval($file->getRealPath()));
        $count = is_string($contents) ? mb_substr_count($contents, "\n") + 1 : 0;
        $made = [];

        for ($line = 1; $line <= $count; $line++) {
            if ($this->makes($covered, $line)) {
                $made[] = $line;
            }
        }

        if (count($made) !== $count) {
            $listed = json_encode([strval($file->getRealPath()), $count, ...$this->spans($made)], self::LINE);
            file_put_contents(sprintf(self::LISTED, $this->results), sprintf("%s\n", $listed), FILE_APPEND);
        }

        return match (true) {
            $made === [] => false,
            count($made) === $count => [],
            default => $made,
        };
    }

    /** Clears what an earlier run listed beside these results, before a run that lists its own. */
    public static function cleared(string $results): void
    {
        $listed = sprintf(self::LISTED, $results);

        if (is_file($listed)) {
            unlink($listed);
        }
    }

    /**
     * The lines a run made each file on, as it listed them beside its
     * results, each file by its path under the root, both as they are on
     * disk; every file whole where
     * it listed none, or none whole.
     */
    public static function madeBeside(string $results, Root $root): MadeLines
    {
        $listed = sprintf(self::LISTED, $results);
        $text = is_file($listed) ? file_get_contents($listed) : false;
        $made = MadeLines::whole();
        $real = realpath($root->value());
        $root = is_string($real) ? Root::of($real) : $root;

        try {
            foreach (is_string($text) ? explode("\n", $text) : [] as $line) {
                $made = $line === '' ? $made : self::readInto($made, Node::decode($line), $root);
            }
        } catch (NotInShape) {
            return MadeLines::whole();
        }

        return $made;
    }

    /** These lines, with the file a line lists. */
    private static function readInto(MadeLines $made, Node $listed, Root $root): MadeLines
    {
        [$file, $count] = $listed->items();
        $spans = [];

        foreach (array_slice($listed->items(), 2) as $span) {
            [$first, $last] = $span->items();
            $spans[] = LineSpan::of(Line::of($first->integer()), Line::of($last->integer()));
        }

        return $made->with(MadeFile::of($root->relative($file->text()), Line::of($count->integer()), ...$spans));
    }

    /** @param array<int, array<int, string>> $covered */
    private function makes(array $covered, int $line): bool
    {
        if (! array_key_exists($line, $covered) || $covered[$line] === []) {
            return true;
        }
        return array_any($covered[$line], fn(string $test): bool => $this->parts->holds($test));
    }

    /**
     * Lines in ascending order as runs of consecutive ones, each its first and last.
     *
     * @param  list<int>             $lines
     * @return list<array{int, int}>
     */
    private function spans(array $lines): array
    {
        $spans = [];

        foreach ($lines as $line) {
            $at = count($spans) - 1;

            if ($at >= 0 && $spans[$at][1] === $line - 1) {
                $spans[$at][1] = $line;

                continue;
            }

            $spans[] = [$line, $line];
        }

        return $spans;
    }
}
