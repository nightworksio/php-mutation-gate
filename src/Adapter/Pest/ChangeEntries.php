<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function explode;
use function is_array;

use NightWorksIO\MutationGate\Core\File\Line;

use function sprintf;
use function sscanf;
use function strval;

/**
 * One file's entries in the plan's table of change places (see ChangeTable):
 * each mutant's spot, its mutator's number in the table, its copy's name and
 * its first and last line, and its place, in the order the whole file's
 * making came to them.
 *
 * @internal
 */
final readonly class ChangeEntries
{
    private const string ENTRY = '%d|%[^|]|%d|%d|%d';

    private function __construct(private string $entries)
    {
    }

    public static function of(string $entries): self
    {
        return new self($entries);
    }

    /**
     * Each spot's places, in the order the whole file's making came to them.
     *
     * @return array<string, list<int>>
     */
    public function free(): array
    {
        $free = [];

        foreach (explode(ChangeTable::BETWEEN, $this->entries) as $entry) {
            [$mutator, $copy, $start, $end, $place] = $this->fieldsOf($entry);
            $free[sprintf(ChangeTable::SPOT, $mutator, $copy, $start, $end)][] = $place;
        }

        return $free;
    }

    /**
     * The line each mutant starts on.
     *
     * @return list<Line>
     */
    public function starts(): array
    {
        $starts = [];

        foreach (explode(ChangeTable::BETWEEN, $this->entries) as $entry) {
            [, , $start] = $this->fieldsOf($entry);
            $starts = $start > 0 ? [...$starts, Line::of($start)] : $starts;
        }

        return $starts;
    }

    /** @return array{int, string, int, int, int} an entry's mutator, copy, first and last line, and place */
    private function fieldsOf(string $entry): array
    {
        $fields = sscanf($entry, self::ENTRY);
        [$mutator, $copy, $start, $end, $place] = is_array($fields) ? $fields : [0, '', 0, 0, 0];

        return [(int) $mutator, strval($copy), (int) $start, (int) $end, (int) $place];
    }
}
