<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_slice;
use function explode;
use function file_get_contents;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\Gauge;
use NightWorksIO\MutationGate\Core\Runner\ProcessUse;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rtrim;
use function sprintf;

/**
 * What a shard's progress says at one time: how long the shard has run, what
 * the machine has left, how many processes run and which hold the most
 * memory, and the last lines the gate said, the longest-running process and
 * the mutant it judges among them (ADR-0157).
 *
 * @internal
 */
final readonly class ProgressReport
{
    /** How many of the processes that hold the most memory it names. */
    private const int LARGEST = 15;

    /** How many of the gate's last lines it repeats. */
    private const int LAST_LINES = 30;

    private const string TEXT = <<<'TEXT'
        mutation-gate: shard %d, %s after it started
        machine: %s
        processes: %d, the largest:
        %s
        the gate's last lines:
        %s

        TEXT;

    private const string INDENTED = '  %s';

    private const string UNKNOWN = 'not known';

    private function __construct(private int $shard, private Gauge $gauge, private string $said)
    {
    }

    /** The report of this shard, with what this gauge reads of the machine and the lines kept in this file. */
    public static function of(int $shard, Gauge $gauge, string $said): self
    {
        return new self($shard, $gauge, $said);
    }

    /** The report this long after the shard started, with what this listing of `ProcessUse::LISTING` printed. */
    public function at(Seconds $since, string $listing): string
    {
        $processes = ProcessUse::parse($listing);
        $remaining = $this->gauge->remaining()->text();

        return sprintf(
            self::TEXT,
            $this->shard,
            $since->text(),
            $remaining === '' ? self::UNKNOWN : $remaining,
            $processes->count(),
            $this->indented($processes->largest(self::LARGEST)),
            $this->indented($this->lastLines()),
        );
    }

    /** @return list<string> */
    private function lastLines(): array
    {
        $kept = is_file($this->said) ? file_get_contents($this->said) : false;
        $text = is_string($kept) ? rtrim($kept, "\n") : '';

        return $text === '' ? [] : array_slice(explode("\n", $text), -self::LAST_LINES);
    }

    /** @param list<string> $lines */
    private function indented(array $lines): string
    {
        $indented = [];

        foreach ($lines as $line) {
            $indented[] = sprintf(self::INDENTED, $line);
        }

        return implode("\n", $indented);
    }
}
