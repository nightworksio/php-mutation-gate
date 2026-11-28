<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_sum;
use function array_unique;
use function array_values;
use function crc32;

use NightWorksIO\MutationGate\Core\Config\StreamJudging;
use NightWorksIO\MutationGate\Core\Mutant\TestShare;
use NightWorksIO\MutationGate\Core\Test\TestFileParts;
use NightWorksIO\MutationGate\Core\Test\TestPart;

use function usort;

/**
 * The test files of a coverage map cut into the parts a run's shards each
 * stream one of (ADR-0117), and the part this shard streams. Each test file,
 * by the file that declares a test's class, is placed by the map's times as
 * {@see TestFileParts} places test files, so every shard cuts the same map
 * alike. A test belongs to the part of its file, and a test
 * of the map declared in no test file the process loaded to the first part,
 * the rest cut as ever (ADR-0171). Judging whole, the shard judges by every
 * covering test each mutant it owns, and leaves the rest; judging parted, it
 * judges every mutant its part's tests cover by those tests (ADR-0174). The
 * lines of a source file no test covers fall to one part alone, where the
 * parts make the mutants their tests reach (ADR-0193); a run that makes only
 * the mutants it is told to, as a share of the second wave does, owns every
 * such line, since each mutant it is told to make is one its part owned.
 *
 * @internal
 */
final readonly class TestParts
{
    /**
     * @param array<string, int> $parts     each test's part, counted from 1, by its id
     * @param array<string, int> $uncovered the part the lines no test covers of each source file fall to, counted
     *                                      from 1, by its real path
     */
    private function __construct(
        private TestPart $part,
        private array $parts,
        private StreamJudging $judging,
        private array $uncovered,
        private bool $toldWhat = false,
    ) {
    }

    /** Every test in one part: a run that cuts no test files. */
    public static function whole(): self
    {
        return new self(TestPart::whole(), [], StreamJudging::Whole, []);
    }

    /**
     * The map's test files, each with the tests it declares, cut into this
     * part's count of parts by the tests' times, this part the shard's.
     *
     * @param array<string, array{files: list<string>, tests: list<string>}> $units by the file declaring each test
     * @param array<string, float>                                           $times each test's seconds, by its id
     */
    public static function cut(TestPart $part, array $units, array $times, StreamJudging $judging): self
    {
        $parts = [];

        foreach (self::assigned($units, $times, $part->parts()) as $file => $at) {
            foreach ($units[$file]['tests'] as $test) {
                $parts[$test] = $at + 1;
            }
        }

        return new self($part, $parts, $judging, []);
    }

    /**
     * These parts, the lines no test covers of each source file falling to
     * one part alone (ADR-0193): the file with the most such lines first,
     * files of as many by path, each to the part with the fewest such lines
     * so far, of those the fewest files, of those the lowest, so every
     * shard spreads them alike.
     *
     * @param array<string, int> $uncovered how many lines of each source file no test covers, by its real path
     */
    public function sharingUncovered(array $uncovered): self
    {
        return new self(
            $this->part,
            $this->parts,
            $this->judging,
            array_map(static fn(int $at): int => $at + 1, TestFileParts::placed($uncovered, $this->part->parts())),
            $this->toldWhat,
        );
    }

    /**
     * These parts, in a run that makes only the mutants it is told to: its
     * part makes and owns the lines no test covers of every file.
     */
    public function makingWhatItIsTold(): self
    {
        return new self($this->part, $this->parts, $this->judging, $this->uncovered, toldWhat: true);
    }

    /**
     * Whether the shard's part makes the lines no test covers of this
     * source file, by its real path: every part, where they fall to none.
     */
    public function makesUncovered(string $file): bool
    {
        return $this->toldWhat
            || ! array_key_exists($file, $this->uncovered)
            || $this->uncovered[$file] === $this->part->number();
    }

    /**
     * Whether the shard owns a mutant no test covers, of this id and in this
     * source file, by its real path: the part its file's lines no test
     * covers fall to (ADR-0193); where they fall to none, the part its id
     * falls to, so one shard judges each such mutant and the parts share
     * them alike (ADR-0171).
     */
    public function ownsUncovered(string $mutant, string $file): bool
    {
        return match (true) {
            $this->toldWhat => true,
            array_key_exists($file, $this->uncovered) => $this->uncovered[$file] === $this->part->number(),
            default => $this->fallsToItsPart($mutant),
        };
    }

    /**
     * Each test file's place among at most this many parts, counted from 0,
     * by the seconds of the tests it declares, as {@see TestFileParts} places
     * test files.
     *
     * @param  array<string, array{files: list<string>, tests: list<string>}> $units
     * @param  array<string, float>                                           $times
     * @return array<string, int>
     */
    public static function assigned(array $units, array $times, int $parts): array
    {
        $seconds = [];

        foreach ($units as $file => $unit) {
            $seconds[$file] = array_sum(array_map(
                static fn(string $test): float => array_key_exists($test, $times) ? $times[$test] : 0.0,
                $unit['tests'],
            ));
        }

        return TestFileParts::placed($seconds, $parts);
    }

    /** Whether the shard's part holds this test. */
    public function holds(string $test): bool
    {
        return $this->partOf($test) === $this->part->number();
    }

    /**
     * Those of a mutant's covering tests the shard's part holds, in their order.
     *
     * @param  list<string> $covering
     * @return list<string>
     */
    public function within(array $covering): array
    {
        $held = [];

        foreach ($covering as $test) {
            $held = $this->holds($test) ? [...$held, $test] : $held;
        }

        return $held;
    }

    /**
     * The covering tests the shard judges a mutant by, whose likely killer,
     * if the ledger names one, is this: judging whole, every one where the
     * shard owns it and none where it does not; judging parted, those its
     * part holds.
     *
     * @param  list<string> $covering
     * @return list<string>
     */
    public function judgedBy(array $covering, string $mutant, string $killer): array
    {
        return match (true) {
            $this->judging === StreamJudging::Parted => $this->within($covering),
            $this->owns($covering, $mutant, $killer) => $covering,
            default => [],
        };
    }

    /**
     * The covering tests the ledger's likely killer of a mutant is sought
     * among: judging whole, every one, so the killer names its owner;
     * judging parted, those the shard's part holds.
     *
     * @param  list<string> $covering
     * @return list<string>
     */
    public function killerAmong(array $covering): array
    {
        return $this->judging === StreamJudging::Whole ? $covering : $this->within($covering);
    }

    /**
     * Whether the shard owns this mutant, whose covering tests these are and
     * whose likely killer this is, '' where the ledger names none: judging
     * whole, its part holds that killer; otherwise its part holds the first
     * covering test; where there are none, its id falls to the part
     * (ADR-0171, ADR-0174).
     *
     * @param list<string> $covering
     */
    public function owns(array $covering, string $mutant, string $killer): bool
    {
        return match (true) {
            $covering === [] => $this->toldWhat || $this->fallsToItsPart($mutant),
            $killer !== '' && $this->judging === StreamJudging::Whole => $this->holds($killer),
            default => $this->holds($covering[0]),
        };
    }

    /**
     * How much of this mutant's covering tests the shard judges it by: every
     * one, or, judging parted, its part's, the shard owning the mutant or not.
     *
     * @param list<string> $covering
     */
    public function shareOf(array $covering, string $mutant, string $killer): TestShare
    {
        return match (true) {
            $this->judgedBy($covering, $mutant, $killer) === $covering => TestShare::All,
            $this->owns($covering, $mutant, $killer) => TestShare::OwnedPart,
            default => TestShare::OtherPart,
        };
    }

    /**
     * A part of these test files and the tests of theirs the units name, in
     * the order a stream runs them: the file whose tests the map timed
     * fastest first, one it timed no test of after every one it did, then by
     * path.
     *
     * @param  list<string>                                                   $files
     * @param  array<string, array{files: list<string>, tests: list<string>}> $units
     * @param  array<string, float>                                           $times
     * @return array{files: list<string>, tests: list<string>}
     */
    public static function fastestFirst(array $files, array $units, array $times): array
    {
        $took = [];

        foreach ($files as $file) {
            $timed = array_intersect_key($times, array_flip($units[$file]['tests']));
            $took[$file] = $timed === [] ? INF : array_sum($timed);
        }

        usort($files, static fn(string $one, string $other): int => [$took[$one], $one] <=> [$took[$other], $other]);

        return [
            'files' => array_values(array_unique(array_merge(...array_map(
                static fn(string $file): array => $units[$file]['files'],
                $files,
            )))),
            'tests' => array_values(array_unique(array_merge(...array_map(
                static fn(string $file): array => $units[$file]['tests'],
                $files,
            )))),
        ];
    }

    /** Whether a mutant's id falls to the shard's part, so the parts share such mutants alike (ADR-0171). */
    private function fallsToItsPart(string $mutant): bool
    {
        return crc32($mutant) % $this->part->parts() + 1 === $this->part->number();
    }

    /** The part a test belongs to; the first, for a test of no file cut. */
    private function partOf(string $test): int
    {
        return array_key_exists($test, $this->parts) ? $this->parts[$test] : 1;
    }
}
