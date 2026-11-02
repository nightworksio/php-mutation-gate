<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function array_key_exists;
use function sprintf;

/**
 * PostgreSQL's tables and sequences in the schemas the connection searches.
 * A sequence a rolled-back insert drew from stays drawn; one never drawn
 * from has no last value, and is set back by restarting it, every other by
 * `setval`.
 *
 * @internal
 */
final readonly class PostgresDialect implements Dialect
{
    private const string TABLES = <<<'SQL'
        SELECT format('%I.%I', schemaname, tablename) FROM pg_tables
        WHERE schemaname = ANY (current_schemas(false))
        SQL;

    private const string COUNTERS = <<<'SQL'
        SELECT format('%I.%I', schemaname, sequencename), last_value FROM pg_sequences
        WHERE schemaname = ANY (current_schemas(false))
        SQL;

    private const string RESTART = 'ALTER SEQUENCE %s RESTART';

    private const string SET = 'SELECT setval(%s, %d, true)';

    /** What a sequence never drawn from holds in place of a last value, read as text. */
    private const string UNDRAWN = '';

    /** @return list<string> */
    public function tables(Statements $database): array
    {
        $tables = [];

        foreach ($database->rows(self::TABLES) as $row) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    /** @return array<string, string> */
    public function counters(Statements $database): array
    {
        $counters = [];

        foreach ($database->rows(self::COUNTERS) as $row) {
            $counters[$row[0]] = $row[1];
        }

        return $counters;
    }

    /**
     * @param array<string, string> $stood
     * @param array<string, string> $now
     */
    public function restore(Statements $database, array $stood, array $now): void
    {
        foreach ($stood as $sequence => $value) {
            if (! array_key_exists($sequence, $now) || $now[$sequence] === $value) {
                continue;
            }

            $database->run(
                $value === self::UNDRAWN
                    ? sprintf(self::RESTART, $sequence)
                    : sprintf(self::SET, $database->quoted($sequence), $value),
            );
        }
    }
}
