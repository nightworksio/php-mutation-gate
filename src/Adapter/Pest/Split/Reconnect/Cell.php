<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_key_exists;

/**
 * The text a database answered at one place of a query's rows (ADR-0147);
 * nothing where the answer holds none there.
 *
 * @internal
 */
final readonly class Cell
{
    /** @param list<list<string>> $rows */
    public static function of(array $rows, int $row, int $column): string
    {
        return array_key_exists($row, $rows) ? self::in($rows[$row], $column) : '';
    }

    /** @param list<string> $row */
    public static function in(array $row, int $column): string
    {
        return array_key_exists($column, $row) ? $row[$column] : '';
    }
}
