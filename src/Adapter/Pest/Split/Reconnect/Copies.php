<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_key_exists;
use function array_keys;
use function array_values;
use function bin2hex;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\LaravelConfig;
use PDO;
use PDOException;

use function random_bytes;
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

    /** The keys of a config that read and write apart. */
    private const array APART = ['read', 'write'];

    /** @param list<array{array<mixed>, Copy}> $copies each copied database's config, and its copy */
    private function __construct(private ?LaravelDatabases $databases, private array $copies)
    {
    }

    public static function none(): self
    {
        return new self(null, []);
    }

    /**
     * The test's databases copied, each connection to them closed; none
     * where any of that cannot be done, the connections then closed or left
     * as they were.
     */
    public static function taken(Descriptors $start, string $directory): ?self
    {
        $databases = LaravelDatabases::current();
        $held = $databases instanceof LaravelDatabases ? self::held($databases) : null;

        if (! $databases instanceof LaravelDatabases || $held === null || $held === []) {
            return null;
        }

        foreach (array_keys($held) as $name) {
            $databases->closed($name);
        }

        return Descriptors::held()->shares($start) ? null : self::copied($databases, $held, $directory);
    }

    /** Each connection to a copied database pointed at its copy, in the child, before its mutant runs. */
    public function reconnected(): void
    {
        foreach ($this->copies as [$config, $copy]) {
            $this->databases?->redirected($config, $copy->database);
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
     * Each connection the application holds connected, by its name, with its
     * config and how to reach its database again; none where one cannot
     * fork with a copy.
     *
     * @return array<string, array{array<mixed>, Driver, Connection}>|null
     */
    private static function held(LaravelDatabases $databases): ?array
    {
        $held = [];

        foreach ($databases->open() as $name => $connection) {
            $pdo = $databases->pdo($connection);

            if (! $pdo instanceof PDO) {
                continue;
            }

            $config = $databases->config($connection);
            $driver = Driver::tryFrom(is_string($config['driver'] ?? null) ? $config['driver'] : '');
            $reached = LaravelConfig::connection($config);

            if ($driver === Driver::Sqlite && ! $reached instanceof Connection) {
                continue;
            }

            if (
                ! $driver instanceof Driver
                || ! $reached instanceof Connection
                || self::apart($config)
                || $databases->inTransaction($connection, $pdo)
                || self::keeps($databases, $name, $config, self::copier($driver), $pdo)
            ) {
                return null;
            }

            $held[$name] = [$config, $driver, $reached];
        }

        return $held;
    }

    /**
     * Whether a session keeps what a copy would lack: state of its own, or a
     * setting a fresh connection of its config does not hold alike.
     *
     * @param array<mixed> $config
     */
    private static function keeps(
        LaravelDatabases $databases,
        string $name,
        array $config,
        Copier $copier,
        PDO $pdo,
    ): bool {
        $session = Session::of($pdo);

        if (! $session instanceof Session) {
            return true;
        }

        $settings = $copier->settings($session);
        $fresh = $databases->freshly($name, $config, static function (PDO $fresh) use ($copier): ?array {
            $session = Session::of($fresh);

            return $session instanceof Session ? $copier->settings($session) : null;
        });

        if ($settings === null || $fresh === null) {
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
     * @param array<string, array{array<mixed>, Driver, Connection}> $held
     */
    private static function copied(LaravelDatabases $databases, array $held, string $directory): ?self
    {
        $copies = [];

        foreach ($held as [$config, $driver, $reached]) {
            $key = serialize($reached);
            $copy = array_key_exists($key, $copies)
                ? $copies[$key][1]
                : self::copyOf($driver, $reached, $config, $directory);

            if (! $copy instanceof Copy) {
                new self($databases, array_values($copies))->dropped();

                return null;
            }

            $copies[$key] = [$config, $copy];
        }

        return new self($databases, array_values($copies));
    }

    /**
     * A copy of the database a connection reaches; none where it cannot be made.
     *
     * @param array<mixed> $config
     */
    private static function copyOf(Driver $driver, Connection $reached, array $config, string $directory): ?Copy
    {
        try {
            return self::copier($driver)->copied($reached, $config, bin2hex(random_bytes(self::TOKEN)), $directory);
        } catch (PDOException) {
            return null;
        }
    }

    /** @param array<mixed> $config */
    private static function apart(array $config): bool
    {
        foreach (self::APART as $key) {
            if (array_key_exists($key, $config)) {
                return true;
            }
        }

        return false;
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
