<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_filter;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;

use ArrayIterator;

use function count;
use function explode;
use function implode;
use function intval;
use function ksort;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sort;

use Traversable;

/**
 * Which tests ran which line of which file, and the executable lines no test
 * ran, as a coverage map holds them: every test the map knows once, in the
 * order it first knew them, and each executable line the places of the tests
 * that ran it in that list (see PlacedLine), none for a line the run missed,
 * as the map's file writes them. A test it knows need not cover anything.
 */
final readonly class LineTests
{
    /** What separates the places of a set's tests in its name. */
    private const string SET_SEPARATOR = ',';

    /**
     * @param list<TestId>                          $tests  every test, in the order the map first knew them
     * @param array<string, int<0, max>>            $places each test's place in the list, by id
     * @param array<string, array<int, PlacedLine>> $lines  each executable line of each file, by path and number
     */
    private function __construct(private array $tests, private array $places, private array $lines)
    {
    }

    public static function none(): self
    {
        return new self([], [], []);
    }

    /**
     * The tests that ran these lines, held at once; a line read twice holds
     * the tests of both, and a line no test ran is one the run missed.
     */
    public static function of(CoveredLine ...$covered): self
    {
        return Tally::of(...$covered);
    }

    /**
     * Lines held as a map's file lists them: every test once, in its order,
     * and each line by the places of its tests in that list, each a place in
     * it; a line placed twice holds the tests of both, and a line no test ran
     * is one the run missed.
     */
    public static function placed(TestIds $tests, PlacedLine ...$placed): self
    {
        return self::none()->knowing(...$tests)->with(...$placed);
    }

    /** These lines, with a test covering a line, where it did not already. */
    public function covered(Path $file, Line $line, TestId $test): self
    {
        $known = $this->knowing($test);

        return $known->with(PlacedLine::of($file, $line->number(), $known->places[$test->value()]));
    }

    /** These lines, knowing these tests too, each one it did not know after those it did. */
    public function knowing(TestId ...$tests): self
    {
        $list = $this->tests;
        $places = $this->places;

        foreach ($tests as $test) {
            if (! array_key_exists($test->value(), $places)) {
                $places[$test->value()] = count($list);
                $list[] = $test;
            }
        }

        return new self($list, $places, $this->lines);
    }

    /** These lines, of these files alone, knowing every test they knew. */
    public function onlyFor(Paths $files): self
    {
        $kept = [];

        foreach ($files as $file) {
            $kept[$file->value()] = $file;
        }

        return new self($this->tests, $this->places, array_intersect_key($this->lines, $kept));
    }

    /**
     * Every executable line: each covered line with the tests that ran it,
     * file by file in the order they were first read, then each line the run
     * missed, with none.
     *
     * @return Traversable<int, CoveredLine>
     */
    public function lines(): Traversable
    {
        foreach ($this->placedLines() as $line) {
            $ids = array_map(fn(int $place): string => $this->tests[$place]->value(), [...$line]);

            yield CoveredLine::of($line->file(), $line->line(), ...$ids);
        }
    }

    /**
     * Every executable line as `lines()` lists them, each with the places of
     * its tests in `tests()`.
     *
     * @return Traversable<int, PlacedLine>
     */
    public function placedLines(): Traversable
    {
        foreach ([false, true] as $missed) {
            foreach ($this->lines as $lines) {
                foreach (self::kept($lines, $missed) as $line) {
                    yield $line;
                }
            }
        }
    }

    public function tests(): TestIds
    {
        return TestIds::of(...$this->tests);
    }

    /** Every file some test ran a line of, in the order they were first read. */
    public function files(): Paths
    {
        $covered = array_filter(
            $this->lines,
            static fn(array $lines): bool => self::kept($lines, missed: false) !== [],
        );

        return Paths::of(...array_map(Path::of(...), array_keys($covered)));
    }

    public function coveredIn(Path $file): Lines
    {
        return Lines::of(...array_map(Line::of(...), array_keys(self::kept($this->linesOf($file), missed: false))));
    }

    /** Every test that ran any line of a file from the first to the last, each once, in the order lines name them. */
    public function running(Path $file, Line $first, Line $last): TestIds
    {
        $lines = $this->linesOf($file);
        $spanned = [];

        for ($line = $first->number(); $line <= $last->number(); $line++) {
            if (array_key_exists($line, $lines)) {
                $spanned[] = $lines[$line];
            }
        }

        return $this->testsOf(...$spanned);
    }

    /** Every test that ran any line of a file. */
    public function runningFile(Path $file): TestIds
    {
        return $this->testsOf(...$this->linesOf($file));
    }

    /** The executable lines of a file, each covered one with the name of its set of tests. */
    public function setsOn(Path $file): LineSets
    {
        $sets = [];

        foreach (self::kept($this->linesOf($file), missed: false) as $number => $line) {
            $places = array_unique([...$line]);
            sort($places);
            $sets[$number] = TestSet::named(implode(self::SET_SEPARATOR, $places));
        }

        ksort($sets);

        $missed = Lines::of(...array_map(Line::of(...), array_keys(self::kept($this->linesOf($file), missed: true))));

        return LineSets::of($this, new ArrayIterator($sets), $missed);
    }

    /** The tests of a set this names, each once, in byte order of their ids. */
    public function testsOfSet(TestSet $set): TestIds
    {
        $ids = [];

        foreach (explode(self::SET_SEPARATOR, $set->name()) as $place) {
            $ids[] = $this->tests[intval($place)]->value();
        }

        sort($ids, SORT_STRING);

        return TestIds::of(...array_map(TestId::of(...), $ids));
    }

    /**
     * The lines no test ran, or those some test ran.
     *
     * @param  array<int, PlacedLine> $lines
     * @return array<int, PlacedLine>
     */
    private static function kept(array $lines, bool $missed): array
    {
        return array_filter($lines, static fn(PlacedLine $line): bool => $line->isMissed() === $missed);
    }

    /** These lines, each placed line after those of its line already held, each test once. */
    private function with(PlacedLine ...$placed): self
    {
        $lines = $this->lines;

        foreach ($placed as $line) {
            $file = $line->file()->value();
            $held = array_key_exists($file, $lines) && array_key_exists($line->line(), $lines[$file])
                ? $lines[$file][$line->line()]
                : NotGiven::value();
            $lines[$file][$line->line()] = $held instanceof PlacedLine ? $held->and($line) : $line;
        }

        return new self($this->tests, $this->places, $lines);
    }

    /** The tests that ran any of these lines, each once, in the order the lines name them. */
    private function testsOf(PlacedLine ...$lines): TestIds
    {
        $tests = [];

        foreach ($lines as $line) {
            foreach ($line as $place) {
                $tests[] = $this->tests[$place];
            }
        }

        return TestIds::of(...$tests);
    }

    /** @return array<int, PlacedLine> */
    private function linesOf(Path $file): array
    {
        return array_key_exists($file->value(), $this->lines) ? $this->lines[$file->value()] : [];
    }
}
