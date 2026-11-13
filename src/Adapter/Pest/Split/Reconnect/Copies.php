<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_key_exists;
use function array_keys;
use function array_values;

use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Unguessable;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Core\NotGiven;
use PDO;
use PDOException;

use function serialize;
use function sort;

/**
 * The databases a stream's parent copied for one child (ADR-0147). A test
 * that holds descriptors the stream did not hold as it started forks where
 * each connection the application holds open is to a database the gate
 * copies, outside a transaction, not read and written apart, with no state
 * its session keeps that a copy would lack and every setting a fresh
 * connection of its config holds; and where, with each of them closed, the
 * process holds no descriptor a child would share. Each database is then
 * copied as its committed state stands, the child points each connection
 * to it at its copy, and the parent drops the copies once the child ended.
 * An SQLite database held in memory forks with the process, and is left as
 * it is.
 *
 * @internal
 */
final readonly class Copies
{
    /** How many random bytes name a copy. */
    private const int TOKEN = 8;

    /** @param list<array{Reach, Copy}> $copies where each copied database is reached, and its copy */
    private function __construct(private LaravelDatabases|NotGiven $databases, private array $copies)
    {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), []);
    }

    /**
     * The test's databases copied, each connection to them closed; none
     * where any of that cannot be done, the connections then closed or left
     * as they were.
     */
    public static function taken(Descriptors $start, string $directory): self|NotGiven
    {
        $databases = LaravelDatabases::current();
        $held = $databases instanceof LaravelDatabases ? self::held($databases) : NotGiven::value();

        if (! $databases instanceof LaravelDatabases || $held instanceof NotGiven || $held === []) {
            return NotGiven::value();
        }

        foreach (array_keys($held) as $name) {
            $databases->closed($name);
        }

        return Descriptors::held()->shares($start) ? NotGiven::value() : self::copied($databases, $held, $directory);
    }

    /** Each connection to a copied database pointed at its copy, in the child, before its mutant runs. */
    public function reconnected(): void
    {
        $databases = $this->databases;

        if (! $databases instanceof LaravelDatabases) {
            return;
        }

        foreach ($this->copies as [$reach, $copy]) {
            $databases->redirected($reach, $copy->database);
        }
    }

    /** Each copy dropped, in the parent, once the child ended. */
    public function dropped(): void
    {
        foreach ($this->copies as [, $copy]) {
            $copy->dropped();
        }
    }

    /**
     * Each connection the application holds connected, by its name, with
     * where it reaches, its driver and how to reach its database again; none
     * where one cannot fork with a copy.
     *
     * @return array<string, array{Reach, Driver, Connection}>|NotGiven
     */
    private static function held(LaravelDatabases $databases): array|NotGiven
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
     * The databases these connections reach, each copied once; none where
     * one cannot be, every copy made then dropped.
     *
     * @param array<string, array{Reach, Driver, Connection}> $held
     */
    private static function copied(LaravelDatabases $databases, array $held, string $directory): self|NotGiven
    {
        $copies = [];

        foreach ($held as [$reach, $driver, $reached]) {
            $key = serialize($reached);
            $copy = array_key_exists($key, $copies)
                ? $copies[$key][1]
                : self::copyOf($driver, $reached, $reach, $directory);

            if (! $copy instanceof Copy) {
                new self($databases, array_values($copies))->dropped();

                return $copy;
            }

            $copies[$key] = [$reach, $copy];
        }

        return new self($databases, array_values($copies));
    }

    /** A copy of the database a connection reaches; none where it cannot be made. */
    private static function copyOf(
        Driver $driver,
        Connection $reached,
        Reach $reach,
        string $directory,
    ): Copy|NotGiven {
        try {
            return self::copier($driver)->copied($reached, $reach, Unguessable::text(self::TOKEN), $directory);
        } catch (PDOException) {
            return NotGiven::value();
        }
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

    private static function copier(Driver $driver): Copier
    {
        return match ($driver) {
            Driver::MySql, Driver::MariaDb => new MySqlCopier(),
            Driver::Postgres => new PostgresCopier(),
            Driver::Sqlite => new SqliteCopier(),
        };
    }
}
