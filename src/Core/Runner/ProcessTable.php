<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_key_exists;
use function array_map;
use function array_values;
use function preg_match_all;
use function sprintf;

/**
 * Every process and the one that started it, as `ps` lists them, which a
 * shell reads to stop a process at its deadline with every process under it,
 * such as a runner's workers and each mutant's own run, so none is left
 * running on its own.
 */
final readonly class ProcessTable
{
    /** The command that lists every process with its parent, one to a line. */
    public const array LISTING = ['ps', '-A', '-o', 'pid=', '-o', 'ppid='];

    /** A line of the listing: a process and its parent. */
    private const string ROW = '/^\s*(?<pid>\d+)\s+(?<parent>\d+)\s*$/m';

    /** The command that stops processes at once, before their ids. */
    private const array KILL = ['kill', '-KILL'];

    /** @param array<int, list<int>> $children each process's children, by its id */
    private function __construct(private array $children)
    {
    }

    /** The table in what the listing printed. */
    public static function parse(string $listing): self
    {
        preg_match_all(self::ROW, $listing, $rows);
        $children = [];

        foreach ($rows['pid'] as $row => $pid) {
            $children[(int) $rows['parent'][$row]][] = (int) $pid;
        }

        return new self($children);
    }

    /**
     * Every process under one, at any depth, each after the processes under it.
     *
     * @return list<int>
     */
    public function descendantsOf(int $pid): array
    {
        $under = [];

        foreach (array_key_exists($pid, $this->children) ? $this->children[$pid] : [] as $child) {
            $under = $child === $pid ? $under : [...$under, ...$this->descendantsOf($child), $child];
        }

        return $under;
    }

    /**
     * The command that stops these processes at once.
     *
     * @return list<string>
     */
    public static function killing(int ...$pids): array
    {
        return [...self::KILL, ...array_map(static fn(int $pid): string => sprintf('%d', $pid), array_values($pids))];
    }
}
