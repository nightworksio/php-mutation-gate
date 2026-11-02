<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;
use function array_values;
use function chr;
use function count;
use function intdiv;

use NightWorksIO\MutationGate\Core\Format\Bytes;

use function ord;
use function sort;
use function sprintf;
use function str_repeat;

/**
 * Every file each file of a graph reaches, worked out once for the whole
 * graph: the files are numbered in byte order, and each file's reach,
 * kept as one bit per file, takes in the reach of every file it leads to
 * until no reach widens. So the files any set of files reaches come out
 * in byte order, at the cost of their own number, rather than of a walk.
 *
 * @internal
 */
final readonly class ReachedFiles
{
    /** Each bit of a byte, by its place, lowest first. */
    private const array BIT = [1, 2, 4, 8, 16, 32, 64, 128];

    /**
     * @param list<string>           $files   every file of the graph, sorted
     * @param array<string, string>  $reaches each file's reach, one bit per file, by its path, as long as its last
     *                                        bit asks
     * @param array<int, list<int>>  $offsets the bits each byte value sets, by the value
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

        sort($files, SORT_STRING);

        return new self($files, self::reachesOf($edges, $files), self::offsets());
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
        $outside = [];

        foreach ($starts as $start) {
            $outside = array_key_exists($start, $this->reaches) ? $outside : [...$outside, $start => $start];
        }

        $reached = [];

        foreach (Bytes::chunks($this->packed(...$starts), 1) as $byte => $bits) {
            foreach ($this->offsets[ord($bits)] as $bit) {
                $reached[] = $this->files[$byte * count(self::BIT) + $bit];
            }
        }

        return $outside === [] ? $reached : $this->sorted([...$reached, ...array_values($outside)]);
    }

    /**
     * What the files of the graph among these reach, packed: a bit for each
     * file of the graph, by its sorted place, eight to a byte, lowest bit
     * first, up to the last a byte reached holds.
     */
    public function packed(string ...$starts): string
    {
        $reach = '';

        foreach ($starts as $start) {
            $reach |= array_key_exists($start, $this->reaches) ? $this->reaches[$start] : '';
        }

        return $reach;
    }

    /**
     * Each file's reach, by its path: its own bit, and the reach of every file
     * it leads to, widened pass after pass until no reach widens.
     *
     * @param  array<string, list<string>> $edges
     * @param  list<string>                $files
     * @return array<string, string>
     */
    private static function reachesOf(array $edges, array $files): array
    {
        $reaches = [];

        foreach ($files as $place => $file) {
            $reaches[$file] = sprintf(
                '%s%s',
                str_repeat(chr(0), intdiv($place, count(self::BIT))),
                chr(self::BIT[$place % count(self::BIT)]),
            );
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
     * The bits each byte value sets, lowest first, by the value: each value
     * made of a lower one and one bit more.
     *
     * @return array<int, list<int>>
     */
    private static function offsets(): array
    {
        $offsets = [0 => []];

        foreach (self::BIT as $bit => $value) {
            foreach ($offsets as $lower => $bits) {
                $offsets[$lower | $value] = [...$bits, $bit];
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
        sort($files, SORT_STRING);

        return $files;
    }
}
