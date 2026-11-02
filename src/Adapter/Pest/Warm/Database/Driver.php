<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_contains;

/**
 * The databases whose state a warm worker can tell and reset (ADR-0023), by
 * the driver name Laravel's database config gives each.
 *
 * @internal
 */
enum Driver: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case Postgres = 'pgsql';
    case Sqlite = 'sqlite';

    /** SQLite's name for a database held in memory, and the query of a URI that holds one there. */
    private const string MEMORY = ':memory:';

    private const string MEMORY_MODE = 'mode=memory';

    /** How the database lists its tables and counters, and sets a counter back. */
    public function dialect(): Dialect
    {
        return match ($this) {
            self::MySql, self::MariaDb => new MySqlDialect(),
            self::Postgres => new PostgresDialect(),
            self::Sqlite => new SqliteDialect(),
        };
    }

    /**
     * PDO's data source name for the database Laravel's config names: by its
     * socket where MySQL's config names one, else by host and port; none for
     * an SQLite database held in memory, which no other process can reach.
     */
    public function dsn(string $host, string $port, string $database, string $socket, string $sslMode): string|NotGiven
    {
        return match (true) {
            $this === self::Sqlite => $database === self::MEMORY || str_contains($database, self::MEMORY_MODE)
                ? NotGiven::value()
                : sprintf('sqlite:%s', $database),
            $this === self::Postgres => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s%s',
                $host,
                $port,
                $database,
                $sslMode === '' ? '' : sprintf(';sslmode=%s', $sslMode),
            ),
            $socket !== '' => sprintf('mysql:unix_socket=%s;dbname=%s', $socket, $database),
            default => sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $database),
        };
    }
}
