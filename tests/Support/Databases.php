<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Dialect;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Image;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Snapshot;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Statements;
use PDO;
use PDOException;
use RuntimeException;

/**
 * The SQLite databases the tests of the warm workers' test databases make,
 * and what they read of them. A database a test makes that PDO refuses is a
 * mistake in the test, so it is answered as one.
 */
final readonly class Databases
{
    /** A database PDO opens by this name, made by running these statements on it. */
    public static function made(string $dsn, string ...$statements): PDO
    {
        try {
            $pdo = new PDO($dsn);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }

        self::ran($pdo, ...$statements);

        return $pdo;
    }

    /** A connection to an empty database held in memory, told to stay silent where a query fails. */
    public static function silenced(): PDO
    {
        try {
            return new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    /** These statements run on the database, in order. */
    public static function ran(PDO $pdo, string ...$statements): void
    {
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    /** The database as it stands. */
    public static function imaged(Statements $database, Dialect $dialect): Image
    {
        try {
            return Image::of($database, $dialect);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    /** @return list<list<string>> */
    public static function rows(Statements $database, string $query): array
    {
        try {
            return $database->rows($query);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    public static function digest(Statements $database, string $query): string
    {
        try {
            return $database->digest($query);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    public static function run(Statements $database, string $statement): void
    {
        try {
            $database->run($statement);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    /** @return list<string> */
    public static function tables(Dialect $dialect, Statements $database): array
    {
        try {
            return $dialect->tables($database);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    /** @return array<string, string> */
    public static function counters(Dialect $dialect, Statements $database): array
    {
        try {
            return $dialect->counters($database);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    /**
     * @param array<string, string> $stood
     * @param array<string, string> $now
     */
    public static function restored(Dialect $dialect, Statements $database, array $stood, array $now): void
    {
        try {
            $dialect->restore($database, $stood, $now);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    public static function holdsOn(Snapshot $snapshot, Statements $database): bool
    {
        try {
            return $snapshot->holdsOn($database);
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    public static function opened(Connection $connection): Statements
    {
        try {
            return $connection->opened();
        } catch (PDOException $refused) {
            throw self::mistaken($refused);
        }
    }

    /** Whether the database refuses the query. */
    public static function refuses(Statements $database, string $query): bool
    {
        try {
            $database->rows($query);
        } catch (PDOException) {
            return true;
        }

        return false;
    }

    /** Whether the database refuses the connection. */
    public static function refusesOpening(Connection $connection): bool
    {
        try {
            $connection->opened();
        } catch (PDOException) {
            return true;
        }

        return false;
    }

    /** A refusal of what a test asked of its own database: a mistake in the test. */
    private static function mistaken(PDOException $refused): RuntimeException
    {
        return new RuntimeException($refused->getMessage(), previous: $refused);
    }
}
