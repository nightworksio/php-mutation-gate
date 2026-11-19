<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_slice;
use function count;
use function intdiv;

use NightWorksIO\MutationGate\Core\Format\Bytes;

use function preg_match_all;

use const PREG_SET_ORDER;

use function sprintf;
use function usort;

/**
 * Every process on the machine with the memory it holds, as `ps` lists
 * them, which a shard's progress says the largest of, so a machine that ran
 * short can be read after its job was lost.
 *
 * @internal
 */
final readonly class ProcessUse
{
    /** The command that lists every process with its parent, its resident memory, its age and its name. */
    public const array LISTING = [...ProcessTable::LISTING, '-orss=', '-oetime=', '-ocomm='];

    /** A line of the listing: a process, its parent, its kibibytes, its age and its name. */
    private const string ROW = '/^\s*(?<pid>\d+)\s+(?<parent>\d+)\s+(?<rss>\d+)\s+(?<age>\S+)\s+(?<name>.+?)\s*$/m';

    private const string LINE = '%d MiB  %s  pid %d of %d  %s';

    /** @param list<array{pid: int, parent: int, rss: int, age: string, name: string}> $processes */
    private function __construct(private array $processes)
    {
    }

    /** The processes the listing printed. */
    public static function parse(string $listing): self
    {
        preg_match_all(self::ROW, $listing, $rows, PREG_SET_ORDER);
        $processes = [];

        foreach ($rows as $row) {
            $processes[] = [
                'pid' => (int) $row['pid'],
                'parent' => (int) $row['parent'],
                'rss' => (int) $row['rss'],
                'age' => $row['age'],
                'name' => $row['name'],
            ];
        }

        return new self($processes);
    }

    /** How many processes the listing printed. */
    public function count(): int
    {
        return count($this->processes);
    }

    /**
     * The processes that hold the most memory, most first, one line each:
     * the memory, the age, the process and its parent, and its name.
     *
     * @return list<string>
     */
    public function largest(int $most): array
    {
        $processes = $this->processes;
        usort($processes, static fn(array $one, array $other): int => $other['rss'] <=> $one['rss']);
        $lines = [];

        foreach (array_slice($processes, 0, $most) as $process) {
            $lines[] = sprintf(
                self::LINE,
                intdiv($process['rss'] * Bytes::PER_KIBIBYTE, Bytes::PER_MEBIBYTE),
                $process['age'],
                $process['pid'],
                $process['parent'],
                $process['name'],
            );
        }

        return $lines;
    }
}
