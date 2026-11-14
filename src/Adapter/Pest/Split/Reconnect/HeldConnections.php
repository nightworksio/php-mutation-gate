<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Core\NotGiven;
use PDO;

use function sort;

/**
 * The connections the application holds that a stream's child can fork
 * with a copy of (ADR-0147): each connected to a database the gate copies,
 * outside a transaction, not read and written apart, with no state its
 * session keeps that a copy would lack and every setting a fresh connection
 * of its config holds. An SQLite database held in memory forks with the
 * process, and is left out.
 *
 * @internal
 */
final readonly class HeldConnections
{
    /**
     * Each connection the application holds connected, by its name, with
     * where it reaches, its driver and how to reach its database again; none
     * where one cannot fork with a copy.
     *
     * @return array<string, array{Reach, Driver, Connection}>|NotGiven
     */
    public static function of(LaravelDatabases $databases): array|NotGiven
    {
        $held = [];

        foreach ($databases->open() as $name => $connection) {
            $pdo = $databases->pdo($connection);
            $reach = $databases->reach($connection);

            if (! $pdo instanceof PDO || $reach->inMemory()) {
                continue;
            }

            $forking = self::forking($databases, $name, $connection, $reach, $pdo);

            if ($forking instanceof NotGiven) {
                return $forking;
            }

            $held[$name] = $forking;
        }

        return $held;
    }

    /** How a driver's databases tell what a session holds, and are copied. */
    public static function copier(Driver $driver): Copier
    {
        return match ($driver) {
            Driver::MySql, Driver::MariaDb => new MySqlCopier(),
            Driver::Postgres => new PostgresCopier(),
            Driver::Sqlite => new SqliteCopier(),
        };
    }

    /**
     * A held connection's reach, driver and how to reach its database again;
     * none where it cannot fork with a copy.
     *
     * @return array{Reach, Driver, Connection}|NotGiven
     */
    private static function forking(
        LaravelDatabases $databases,
        string $name,
        object $connection,
        Reach $reach,
        PDO $pdo,
    ): array|NotGiven {
        $driver = $reach->driver;
        $reached = $reach->connection;

        if (! $driver instanceof Driver || ! $reached instanceof Connection || $reach->apart) {
            return NotGiven::value();
        }

        $kept = $databases->inTransaction($connection, $pdo)
            || self::keeps($databases, $name, $connection, $driver, $pdo);

        return $kept
            ? NotGiven::value()
            : [$reach, $driver, $reached];
    }

    /**
     * Whether a session keeps what a copy would lack: state of its own, or a
     * setting a fresh connection of its config does not hold alike.
     */
    private static function keeps(
        LaravelDatabases $databases,
        string $name,
        object $connection,
        Driver $driver,
        PDO $pdo,
    ): bool {
        $session = Session::of($pdo);
        $copier = self::copier($driver);

        if (! $session instanceof Session) {
            return true;
        }

        $settings = $copier->settings($session);
        $read = static fn(PDO $fresh): array|NotGiven => self::settings($copier, $fresh);
        $fresh = $databases->freshly($name, $connection, $read);

        if ($settings instanceof NotGiven || $fresh instanceof NotGiven) {
            return true;
        }

        sort($settings);
        sort($fresh);

        return $settings !== $fresh || $copier->holdsState($session);
    }

    /**
     * Each setting a session of this connection holds; none where the database cannot tell.
     *
     * @return list<list<string>>|NotGiven
     */
    private static function settings(Copier $copier, PDO $pdo): array|NotGiven
    {
        $session = Session::of($pdo);

        return $session instanceof Session ? $copier->settings($session) : $session;
    }
}
