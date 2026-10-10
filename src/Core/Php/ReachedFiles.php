<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_fill;
use function array_key_exists;
use function array_keys;
use function array_values;
use function chr;
use function count;
use function intdiv;
use function ord;
use function sort;
use function str_repeat;

/**
 * Every file each file of a graph reaches, worked out once for the whole
 * graph: the files are numbered in sorted order, and each file's reach,
 * kept as one bit per file, takes in the reach of every file it leads to
 * until no reach widens. So the files any set of files reaches come out
 * sorted, at the cost of their own number, rather than of a walk.
 */
final readonly class ReachedFiles
{
    /** How many files one byte of a reach holds. */
    private const int BITS = 8;

    /** Each bit of a byte, by its place, lowest first. */
    private const array BIT = [1, 2, 4, 8, 16, 32, 64, 128];

    /**
     * @param list<string>          $files    every file of the graph, sorted
     * @param array<string, string> $reaches  each file's reach, one bit per file, by its path
     * @param list<list<int>>       $offsets  the bits each byte value sets, by the value
     */
    private function __construct(private array $files, private array $reaches, private array $offsets)
    {
    }

    /** @param array<string, list<string>> $edges where each file leads, by its path */
    public static function over(array $edges): self
    {
        $files = [];

        foreach ($edges as $file => $targets) {
            $files[$file] = $file;

            foreach ($targets as $target) {
                $files[$target] = $target;
            }
        }

        $sorted = array_values($files);
        sort($sorted);
        $places = [];

        foreach ($sorted as $place => $file) {
            $places[$file] = $place;
        }

        return new self($sorted, self::reachesOf($edges, $sorted, $places), self::offsets());
    }

    /** @return list<string> every file of the graph, sorted */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * These files and every file they reach, each once, sorted; a file the
     * graph does not hold reaches only itself.
     *
     * @return list<string>
     */
    public function from(string ...$starts): array
    {
        $bytes = intdiv(count($this->files) + self::BITS - 1, self::BITS);
        $reach = str_repeat(chr(0), $bytes);
        $outside = [];

        foreach ($starts as $start) {
            if (! array_key_exists($start, $this->reaches)) {
                $outside[$start] = $start;

                continue;
            }

            $reach |= $this->reaches[$start];
        }

        $reached = [];

        for ($byte = 0; $byte < $bytes; $byte++) {
            foreach ($this->offsets[ord($reach[$byte])] as $bit) {
                $reached[] = $this->files[$byte * self::BITS + $bit];
            }
        }

        return $outside === [] ? $reached : $this->sorted([...$reached, ...array_values($outside)]);
    }

    /**
     * Each file's reach, by its path: its own bit, and the reach of every file
     * it leads to, widened pass after pass until no reach widens.
     *
     * @param  array<string, list<string>> $edges
     * @param  list<string>                $files
     * @param  array<string, int>          $places
     * @return array<string, string>
     */
    private static function reachesOf(array $edges, array $files, array $places): array
    {
        $empty = str_repeat(chr(0), intdiv(count($files) + self::BITS - 1, self::BITS));
        $reaches = [];

        foreach ($files as $file) {
            $at = intdiv($places[$file], self::BITS);
            $reaches[$file] = $empty;
            $reaches[$file][$at] = chr(self::BIT[$places[$file] % self::BITS]);
        }

        do {
            [$reaches, $widened] = self::widened($edges, $reaches);
        } while ($widened);

        return $reaches;
    }

    /**
     * Each reach widened by the reaches of the files it leads to, as far as
     * one pass carries it, and whether any widened.
     *
     * @param  array<string, list<string>> $edges
     * @param  array<string, string>       $reaches
     * @return array{array<string, string>, bool}
     */
    private static function widened(array $edges, array $reaches): array
    {
        $widened = false;

        foreach ($edges as $file => $targets) {
            $reach = $reaches[$file];

            foreach ($targets as $target) {
                $reach |= $reaches[$target];
            }

            $widened = $widened || $reach !== $reaches[$file];
            $reaches[$file] = $reach;
        }

        return [$reaches, $widened];
    }

    /**
     * The bits each byte value sets, lowest first, by the value.
     *
     * @return list<list<int>>
     */
    private static function offsets(): array
    {
        $offsets = array_fill(0, 1 << self::BITS, []);

        foreach (array_keys($offsets) as $value) {
            for ($bit = 0; $bit < self::BITS; $bit++) {
                $offsets[$value] = ($value & self::BIT[$bit]) === 0 ? $offsets[$value] : [...$offsets[$value], $bit];
            }
        }

        return $offsets;
    }

    /**
     * @param  list<string> $files
     * @return list<string>
     */
    private function sorted(array $files): array
    {
        sort($files);

        return $files;
    }
}
