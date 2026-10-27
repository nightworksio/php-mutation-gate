<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function array_key_exists;

use PDOException;

use function sprintf;
use function str_replace;

/**
 * MySQL's and MariaDB's tables, and each table's `AUTO_INCREMENT`, which a
 * rolled-back insert leaves moved on. MySQL caches what its information
 * schema says of a table for a day unless the session asks it not to;
 * MariaDB has no such cache, and no setting to ask with. A table's counter
 * is set back by `ALTER TABLE`, which waits for no lock longer than a test
 * database's own process could hold one.
 */
final readonly class MySqlDialect implements Dialect
{
    private const string TABLES = "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'";

    private const string UNCACHED = 'SET SESSION information_schema_stats_expiry = 0';

    private const string COUNTERS = <<<'SQL'
        SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL
        SQL;

    private const string LOCK_WAIT = 'SET SESSION lock_wait_timeout = 10';

    private const string RESTORE = 'ALTER TABLE %s AUTO_INCREMENT = %d';

    /** @return list<string> */
    public function tables(Statements $database): array
    {
        $tables = [];

        foreach ($database->rows(self::TABLES) as $row) {
            $tables[] = $this->quoted($row[0]);
        }

        return $tables;
    }

    /** @return array<string, string> */
    public function counters(Statements $database): array
    {
        $this->uncached($database);
        $counters = [];

        foreach ($database->rows(self::COUNTERS) as $row) {
            $counters[$this->quoted($row[0])] = $row[1];
        }

        return $counters;
    }

    /**
     * @param array<string, string> $stood
     * @param array<string, string> $now
     */
    public function restore(Statements $database, array $stood, array $now): void
    {
        $database->run(self::LOCK_WAIT);

        foreach ($stood as $table => $value) {
            if (array_key_exists($table, $now) && $now[$table] !== $value) {
                $database->run(sprintf(self::RESTORE, $table, $value));
            }
        }
    }

    /** The information schema read live, where the database caches it and can be asked not to. */
    private function uncached(Statements $database): void
    {
        try {
            $database->run(self::UNCACHED);
        } catch (PDOException) {
            return;
        }
    }

    private function quoted(string $table): string
    {
        return sprintf('`%s`', str_replace('`', '``', $table));
    }
}
