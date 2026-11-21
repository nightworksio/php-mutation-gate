<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_key_exists;
use function count;
use function explode;
use function intdiv;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_match;
use function preg_match_all;

use const PREG_SET_ORDER;

/**
 * Every process on the machine at one moment, with its parent, its group,
 * the memory it holds, its age and its name, as Linux's `/proc` says them or,
 * on a system without it, as `ps` lists them: the one listing the gate stops
 * a process with every process under it by, tells the processes it spares
 * by, and reports a shard's processes by (ADR-0312).
 *
 * @internal
 */
final readonly class ProcessListing
{
    /** The command that lists every process, one to a line, on a system without `/proc`. */
    public const array COMMAND = [
        'ps', '-A', '-o', 'pid=', '-o', 'ppid=', '-o', 'pgid=', '-o', 'rss=', '-o', 'etime=', '-o', 'comm=',
    ];

    /** A line of the command's listing: a process, its parent, its group, its kibibytes, its age and its name. */
    private const string ROW = <<<'REGEX'
        /^\s*(?<pid>\d+)\s+(?<parent>\d+)\s+(?<group>\d+)\s+(?<rss>\d+)\s+
        (?:(?:(?<days>\d+)-)?(?<hours>\d+):)?(?<minutes>\d+):(?<seconds>\d+)\s+(?<name>.+?)\s*$/mx
        REGEX;

    /** A process's `stat` file: its id, its name up to the last ")", which a name may hold, and its other fields. */
    private const string STAT = '/^(?<pid>\d+) \((?<name>.*)\) (?<fields>.*)$/s';

    /** The place of each field `stat` holds after the name, counted from 0. */
    private const int PARENT = 1;

    private const int GROUP = 2;

    private const int STARTED = 19;

    private const int PAGES = 21;

    /** @param array<int, array{parent: int, group: int, rss: int, age: int, name: string}> $processes by pid */
    private function __construct(private array $processes)
    {
    }

    /** The processes the command's listing printed; a line that names none is left out. */
    public static function printed(string $listing): self
    {
        preg_match_all(self::ROW, $listing, $rows, PREG_SET_ORDER);
        $processes = [];

        foreach ($rows as $row) {
            $processes[(int) $row['pid']] = [
                'parent' => (int) $row['parent'],
                'group' => (int) $row['group'],
                'rss' => (int) $row['rss'],
                'age' => (int) $row['days'] * Seconds::PER_DAY + (int) $row['hours'] * Seconds::PER_HOUR
                    + (int) $row['minutes'] * Seconds::PER_MINUTE + (int) $row['seconds'],
                'name' => $row['name'],
            ];
        }

        return new self($processes);
    }

    /**
     * The processes these `stat` files of `/proc` say, on a machine up this
     * many seconds, whose clock ticks this many times a second and whose
     * pages hold this many bytes; a file that is not whole is left out.
     *
     * @param list<string> $stats
     */
    public static function stated(array $stats, float $uptime, int $ticks, int $page): self
    {
        $processes = [];

        foreach ($stats as $stat) {
            $fields = preg_match(self::STAT, $stat, $parts) === 1 ? explode(' ', $parts['fields']) : [];

            if (count($fields) > self::PAGES) {
                $processes[(int) $parts['pid']] = [
                    'parent' => (int) $fields[self::PARENT],
                    'group' => (int) $fields[self::GROUP],
                    'rss' => intdiv((int) $fields[self::PAGES] * $page, Bytes::PER_KIBIBYTE),
                    'age' => (int) ($uptime - (int) $fields[self::STARTED] / $ticks),
                    'name' => $parts['name'],
                ];
            }
        }

        return new self($processes);
    }

    /**
     * Every process under one, at any depth, each after the processes under it.
     *
     * @return list<int>
     */
    public function descendantsOf(int $pid): array
    {
        $children = [];

        foreach ($this->processes as $child => $process) {
            $children[$process['parent']][] = $child;
        }

        return self::under($children, $pid);
    }

    /**
     * Each process's parent, by its id.
     *
     * @return array<int, int>
     */
    public function parents(): array
    {
        $parents = [];

        foreach ($this->processes as $pid => $process) {
            $parents[$pid] = $process['parent'];
        }

        return $parents;
    }

    /** The group of the process with this id; the id itself where it holds no such process. */
    public function groupOf(int $pid): int
    {
        return array_key_exists($pid, $this->processes) ? $this->processes[$pid]['group'] : $pid;
    }

    /**
     * Every process, with its kibibytes, its age in seconds and its name.
     *
     * @return list<array{pid: int, parent: int, rss: int, age: int, name: string}>
     */
    public function uses(): array
    {
        $uses = [];

        foreach ($this->processes as $pid => $process) {
            $uses[] = [
                'pid' => $pid,
                'parent' => $process['parent'],
                'rss' => $process['rss'],
                'age' => $process['age'],
                'name' => $process['name'],
            ];
        }

        return $uses;
    }

    /**
     * @param  array<int, list<int>> $children
     * @return list<int>
     */
    private static function under(array $children, int $pid): array
    {
        $under = [];

        foreach (array_key_exists($pid, $children) ? $children[$pid] : [] as $child) {
            $under = $child === $pid ? $under : [...$under, ...self::under($children, $child), $child];
        }

        return $under;
    }
}
