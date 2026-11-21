<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_slice;
use function count;
use function intdiv;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function usort;

/**
 * Every process on the machine with the memory it holds, as a listing holds
 * them (ProcessListing), which a shard's progress says the largest of, so a
 * machine that ran short can be read after its job was lost.
 *
 * @internal
 */
final readonly class ProcessUse
{
    private const string LINE = '%d MiB  %s  pid %d of %d  %s';

    /** A process's age as `ps` says it: minutes and seconds, the hours before them, the days before those. */
    private const string MINUTES = '%02d:%02d';

    private const string HOURS = '%02d:%s';

    private const string DAYS = '%d-%s';

    /** @param list<array{pid: int, parent: int, rss: int, age: int, name: string}> $processes */
    private function __construct(private array $processes)
    {
    }

    /** The processes the listing holds. */
    public static function of(ProcessListing $listing): self
    {
        return new self($listing->uses());
    }

    /** How many processes the listing holds. */
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
                $this->age($process['age']),
                $process['pid'],
                $process['parent'],
                $process['name'],
            );
        }

        return $lines;
    }

    private function age(int $seconds): string
    {
        $days = intdiv($seconds, Seconds::PER_DAY);
        $hours = intdiv($seconds % Seconds::PER_DAY, Seconds::PER_HOUR);
        $minutes = sprintf(
            self::MINUTES,
            intdiv($seconds % Seconds::PER_HOUR, Seconds::PER_MINUTE),
            $seconds % Seconds::PER_MINUTE,
        );

        return match (true) {
            $days > 0 => sprintf(self::DAYS, $days, sprintf(self::HOURS, $hours, $minutes)),
            $hours > 0 => sprintf(self::HOURS, $hours, $minutes),
            default => $minutes,
        };
    }
}
