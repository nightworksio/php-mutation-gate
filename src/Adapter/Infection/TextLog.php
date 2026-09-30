<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_filter;
use function array_key_exists;
use function array_key_last;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

use function preg_match;
use function sprintf;
use function trim;

/**
 * Infection's text log (`logs.text`): the mutants it names under the heading
 * of each status, each as `<n>) <path>:<line>    [M] <mutator> [ID] <id>`
 * followed by its diff. It is the only place the skipped mutants are named,
 * and it gives the native id of every mutant it names.
 *
 * @phpstan-type Entry array{status: string, file: string, line: int, mutator: string, id: string, diff: list<string>}
 */
final readonly class TextLog
{
    public const string SKIPPED = 'Skipped';

    private const string HEADING = '/^(?<status>[A-Za-z ]+) mutants:$/';

    private const string UNDERLINE = '/^=+$/';

    private const string ENTRY = '/^\d+\) (?<file>.+):(?<line>\d+)    \[M\] (?<mutator>\S+) \[ID\] (?<id>\S+)$/';

    /** @param list<Entry> $entries */
    private function __construct(private array $entries)
    {
    }

    /** The log a run wrote; a run that wrote none names no mutant. */
    public static function at(string $file): self
    {
        return self::read(is_file($file) ? sprintf('%s', file_get_contents($file)) : '');
    }

    public static function read(string $text): self
    {
        $lines = explode("\n", $text);
        $entries = [];
        $status = '';

        foreach ($lines as $index => $line) {
            $heading = self::headingAt($lines, $index);
            $status = $heading === '' ? $status : $heading;
            $entries = $heading === '' ? self::fed($entries, $status, $line) : $entries;
        }

        return new self($entries);
    }

    /**
     * The mutants named under one heading, each with its file on disk, line,
     * mutator, native id and diff.
     *
     * @return list<array{file: string, line: int, mutator: string, id: string, diff: string}>
     */
    public function under(string $status): array
    {
        $named = [];

        foreach ($this->entries as $entry) {
            if ($entry['status'] === $status) {
                $named[] = [...$entry, 'diff' => trim(implode("\n", $entry['diff']), "\n")];
            }
        }

        return $named;
    }

    /**
     * The native id the log gives a mutant, found by where it is, its mutator
     * and its change; none where it names none.
     */
    public function idOf(string $file, int $line, string $mutator, string $diff): string
    {
        $change = MutantId::hash(Path::of($file), $mutator, $diff, 0)->value();

        foreach ($this->entries as $entry) {
            $logged = MutantId::hash(Path::of($file), $mutator, implode("\n", $entry['diff']), 0)->value();

            $there = $entry['file'] === $file && $entry['line'] === $line;

            if ($there && $entry['mutator'] === $mutator && $logged === $change) {
                return $entry['id'];
            }
        }

        return '';
    }

    /** How many mutants are named under one heading. */
    public function count(string $status): int
    {
        return count(array_filter($this->entries, static fn(array $entry): bool => $entry['status'] === $status));
    }

    /**
     * The status a heading at this line names, with its underline below it; none where it is not one.
     *
     * @param list<string> $lines
     */
    private static function headingAt(array $lines, int $index): string
    {
        $below = array_key_exists($index + 1, $lines) ? $lines[$index + 1] : '';

        return preg_match(self::HEADING, $lines[$index], $heading) === 1 && preg_match(self::UNDERLINE, $below) === 1
            ? $heading['status']
            : '';
    }

    /**
     * The entries with one more line read: a new entry, a line of the last
     * entry's diff, or nothing that belongs to one.
     *
     * @param list<Entry> $entries
     *
     * @return list<Entry>
     */
    private static function fed(array $entries, string $status, string $line): array
    {
        $last = array_key_last($entries);

        if (preg_match(self::ENTRY, $line, $entry) === 1) {
            return [...$entries, [
                'status' => $status,
                'file' => $entry['file'],
                'line' => (int) $entry['line'],
                'mutator' => $entry['mutator'],
                'id' => $entry['id'],
                'diff' => [],
            ]];
        }

        if ($last === null || preg_match(self::UNDERLINE, $line) === 1 || $entries[$last]['status'] !== $status) {
            return $entries;
        }

        $entries[$last]['diff'][] = $line;

        return $entries;
    }
}
