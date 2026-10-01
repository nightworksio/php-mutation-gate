<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_replace;
use function array_sum;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where the gate's own engine makes the mutants of the files it counted: how
 * many start on each line. A file it counted with no mutant holds no line,
 * and a file it never counted is not held at all.
 */
final readonly class MutantSites
{
    /** @param array<non-empty-string, array<int, positive-int>> $counts how many start on each line, by file */
    private function __construct(private array $counts)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** The mutants of a file, one starting on each of these lines: a line given twice holds two. */
    public static function inFile(Path $file, Line ...$starts): self
    {
        $counts = [];

        foreach ($starts as $start) {
            $counts[$start->number()] = array_key_exists($start->number(), $counts) ? $counts[$start->number()] + 1 : 1;
        }

        return new self([$file->value() => $counts]);
    }

    /** These sites and another's; where both counted a file, the other's count stands. */
    public function and(self $other): self
    {
        return new self(array_replace($this->counts, $other->counts));
    }

    /** Whether the engine counted this file. */
    public function has(Path $file): bool
    {
        return array_key_exists($file->value(), $this->counts);
    }

    /** Every file the engine counted. */
    public function files(): Paths
    {
        return Paths::of(...array_map(Path::of(...), array_keys($this->counts)));
    }

    /** The first file the engine counted, or none where it counted none. */
    public function first(): Path|NotGiven
    {
        $first = array_key_first($this->counts);

        return $first === null ? NotGiven::value() : Path::of($first);
    }

    /** Each line of a file a mutant starts on. */
    public function linesOf(Path $file): Lines
    {
        return Lines::of(...array_map(Line::of(...), array_keys($this->countsOf($file))));
    }

    /** How many mutants start on a line of a file. */
    public function countAt(Path $file, Line $line): int
    {
        $counts = $this->countsOf($file);

        return array_key_exists($line->number(), $counts) ? $counts[$line->number()] : 0;
    }

    /** How many mutants the engine made in every file it counted. */
    public function count(): int
    {
        return array_sum(array_map(array_sum(...), $this->counts));
    }

    /** @return array<int, positive-int> */
    private function countsOf(Path $file): array
    {
        return array_key_exists($file->value(), $this->counts) ? $this->counts[$file->value()] : [];
    }
}
