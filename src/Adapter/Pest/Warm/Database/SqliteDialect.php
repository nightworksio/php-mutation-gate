<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function sprintf;
use function str_replace;

/**
 * SQLite's tables, `sqlite_sequence` among them: SQLite keeps each
 * `AUTOINCREMENT` counter in that table, so a rolled-back insert leaves
 * none moved, and there is nothing to set back.
 *
 * @internal
 */
final readonly class SqliteDialect implements Dialect
{
    private const string TABLES = "SELECT name FROM sqlite_master WHERE type = 'table'";

    /** @return list<string> */
    public function tables(Statements $database): array
    {
        $tables = [];

        foreach ($database->rows(self::TABLES) as $row) {
            $tables[] = sprintf('"%s"', str_replace('"', '""', $row[0]));
        }

        return $tables;
    }

    /** @return array<string, string> */
    public function counters(Statements $database): array
    {
        return [];
    }

    /**
     * @param array<string, string> $stood
     * @param array<string, string> $now
     */
    public function restore(Statements $database, array $stood, array $now): void
    {
    }
}
