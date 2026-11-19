<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_values;
use function explode;
use function getenv;
use function getmypid;
use function is_array;
use function is_string;

use LogicException;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copy;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\HeldConnections;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Reach;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Session;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use PDO;
use PDOException;
use RuntimeException;

use function sprintf;

/**
 * The database servers the tests of reconnecting streams run against: each
 * named by the address its variable holds, `host:port`, as CI's services
 * hand them, its user the one its image starts with and no password. A test
 * makes a database of its process's own on one, so the mutants of a shard
 * that run side by side share none; SQLite has no server.
 */
final readonly class Servers
{
    /** Whether no running server of this kind is named, so its tests are skipped. */
    public static function missing(Driver $driver): bool
    {
        return self::address($driver) === [];
    }

    /** The variable that names a server of this kind. */
    public static function variable(Driver $driver): string
    {
        return match ($driver) {
            Driver::MySql => 'TEST_SERVER_MYSQL',
            Driver::MariaDb => 'TEST_SERVER_MARIADB',
            Driver::Postgres => 'TEST_SERVER_POSTGRES',
            Driver::Sqlite => '',
        };
    }

    /** A connection to this database on the server, as an application's config gives it, as this user or the image's. */
    public static function connection(Driver $driver, string $database, string $user = ''): Connection
    {
        return Connection::of($driver, self::dsn($driver, $database), $user === '' ? self::user($driver) : $user, '', [], []);
    }

    /** A database name of this process's own, so tests that run side by side on one server never share one. */
    public static function own(string $name): string
    {
        return sprintf('%s_%d', $name, getmypid());
    }

    /** A PDO of this database on the server, made by running these statements on it. */
    public static function opened(Driver $driver, string $database, string ...$statements): PDO
    {
        try {
            $pdo = new PDO(self::dsn($driver, $database), self::user($driver), '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]);
        } catch (PDOException $refused) {
            throw new RuntimeException(sprintf('The test cannot open %s: %s', $database, $refused->getMessage()), 0, $refused);
        }

        Databases::ran($pdo, ...$statements);

        return $pdo;
    }

    /** A new database of this name on the server, after these statements ran in it; one of that name before is dropped. */
    public static function made(Driver $driver, string $database, string ...$statements): string
    {
        Databases::ran(
            self::opened($driver, self::maintenance($driver)),
            sprintf('DROP DATABASE IF EXISTS %s', $database),
            sprintf('CREATE DATABASE %s', $database),
        );
        self::opened($driver, $database, ...$statements);

        return $database;
    }

    /** Whether the server holds this database. */
    public static function holds(Driver $driver, string $database): bool
    {
        $query = $driver === Driver::Postgres
            ? 'SELECT COUNT(*) FROM pg_database WHERE datname = ?'
            : 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?';

        return self::rows($driver, self::maintenance($driver), $query, $database) !== [['0']];
    }

    /**
     * The rows a query of a database on the server reads, each value as text.
     *
     * @return list<list<string>>
     */
    public static function rows(Driver $driver, string $database, string $query, string ...$bound): array
    {
        $asked = self::opened($driver, $database)->prepare($query);

        if ($asked === false || ! $asked->execute($bound)) {
            throw new RuntimeException(sprintf('The test cannot ask %s: %s', $database, $query));
        }

        $rows = [];

        foreach ($asked->fetchAll(PDO::FETCH_NUM) as $row) {
            $rows[] = is_array($row) ? array_values(array_filter($row, is_string(...))) : [];
        }

        return $rows;
    }

    /** The session of a new database of a test's own on this server, after these statements ran in it. */
    public static function session(Driver $driver, string ...$statements): Session
    {
        $session = Session::of(self::opened($driver, self::made($driver, self::own('pmg_session')), ...$statements));

        return $session instanceof Session
            ? $session
            : throw new LogicException('The test\'s connection reports its errors other than by an exception.');
    }

    /**
     * The copy the copier of this server makes of a new database of a test's own on it, after these statements ran
     * in it; the rows this query reads of the copy, before it is dropped; and whether the server still holds the copy
     * after. Why the copier made none, where it did not.
     *
     * @return array{list<list<string>>|string, bool}
     */
    public static function copied(Driver $driver, string $query, string ...$schema): array
    {
        $database = self::made($driver, self::own('pmg_original'), ...$schema);
        $reach = new Reach(
            $driver,
            self::connection($driver, $database),
            $database,
            apart: false,
            where: 'the default connection',
            elsewhere: static fn(string $other): Connection => self::connection($driver, $other),
        );

        try {
            $copy = HeldConnections::copier($driver)->copied(self::connection($driver, $database), $reach, (string) getmypid(), '');
        } catch (PDOException $refused) {
            return [$refused->getMessage(), false];
        }

        if (! $copy instanceof Copy) {
            return ['no copy', false];
        }

        $rows = self::rows($driver, $copy->database, $query);
        $copy->dropped();

        return [$rows, self::holds($driver, $copy->database)];
    }

    private static function dsn(Driver $driver, string $database): string
    {
        $address = self::address($driver);
        $dsn = $driver === Driver::Postgres ? 'pgsql:host=%s;port=%s;dbname=%s' : 'mysql:host=%s;port=%s;dbname=%s';

        return sprintf($dsn, $address[0] ?? '', $address[1] ?? '', $database);
    }

    private static function user(Driver $driver): string
    {
        return $driver === Driver::Postgres ? 'postgres' : 'root';
    }

    /** The database every server of this kind holds, which a test's own is made and dropped from. */
    private static function maintenance(Driver $driver): string
    {
        return $driver === Driver::Postgres ? 'postgres' : 'mysql';
    }

    /** @return list<string> the server's host and port; none where its variable names none */
    private static function address(Driver $driver): array
    {
        $variable = self::variable($driver);
        $named = $variable === '' ? false : getenv($variable);

        return is_string($named) && $named !== '' ? explode(':', $named, 2) : [];
    }
}
