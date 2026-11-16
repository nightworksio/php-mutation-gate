<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_any;
use function array_fill_keys;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;

use Closure;

use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\ByName;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Laravel;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\LaravelConfig;
use NightWorksIO\MutationGate\Core\NotGiven;
use PDO;
use PDOException;

use function serialize;

/**
 * What a stream reads of the application's databases, and tells them, by
 * name, since the gate does not require Laravel (ADR-0147): the connections
 * its database manager holds, each one's config, PDO and transactions, a
 * fresh connection made from a config as the manager makes one, a
 * connection closed so that it reconnects as it is next used, and each
 * connection to a database pointed at its copy.
 *
 * @internal
 */
final readonly class LaravelDatabases
{
    private const string MANAGER = 'db';

    private const string FACTORY = 'db.factory';

    private const string CONFIG = 'config';

    private const string CONNECTIONS = 'database.connections';

    /** The key of the driver a connection's config names. */
    private const string DRIVER = 'driver';

    /** The keys of a config that read and write apart. */
    private const array APART = ['read', 'write'];

    /** The keys of a config that say which database it reaches. */
    private const array REACHES = [self::DRIVER, 'host', 'port', 'unix_socket', LaravelConfig::DATABASE];

    private function __construct(private object $application, private object $manager)
    {
    }

    /** The current application's databases; none where there is no application, or it binds no database manager. */
    public static function current(): self|NotGiven
    {
        $application = ByName::called(Laravel::CONTAINER, 'getInstance');
        $bound = ByName::called($application, 'bound', self::MANAGER) === true
            && ByName::called($application, 'bound', self::FACTORY) === true
            && ByName::called($application, 'bound', self::CONFIG) === true;
        $manager = $bound ? ByName::called($application, 'make', self::MANAGER) : null;

        return is_object($application) && is_object($manager) ? new self($application, $manager) : NotGiven::value();
    }

    /** @return array<string, object> each connection the manager holds, by its name */
    public function open(): array
    {
        $open = ByName::called($this->manager, 'getConnections');
        $connections = [];

        foreach (is_array($open) ? $open : [] as $name => $connection) {
            if (is_string($name) && is_object($connection)) {
                $connections[$name] = $connection;
            }
        }

        return $connections;
    }

    /** Where the connection reaches, as its config says. */
    public function reach(object $connection): Reach
    {
        $config = $this->config($connection);
        $driver = Driver::tryFrom(array_key_exists(self::DRIVER, $config) && is_string($config[self::DRIVER])
            ? $config[self::DRIVER]
            : '');

        return new Reach(
            $driver ?? NotGiven::value(),
            LaravelConfig::connection($config),
            LaravelConfig::database($config),
            array_any(self::APART, static fn(string $key): bool => array_key_exists($key, $config)),
            $this->where($config),
            static fn(string $database): Connection|NotGiven => LaravelConfig::connection(
                [...$config, LaravelConfig::DATABASE => $database],
            ),
        );
    }

    /** The connection's PDO, where it is connected; none where it would connect as it is next used. */
    public function pdo(object $connection): PDO|NotGiven
    {
        $pdo = ByName::called($connection, 'getRawPdo');

        return $pdo instanceof PDO ? $pdo : NotGiven::value();
    }

    /** Whether the connection is inside a transaction, by Laravel's count or by PDO's own. */
    public function inTransaction(object $connection, PDO $pdo): bool
    {
        $level = ByName::called($connection, 'transactionLevel');

        return ! is_int($level) || $level !== 0 || $pdo->inTransaction();
    }

    /**
     * What this reads of a fresh connection of this connection's config, made
     * as the manager makes one, closed once read; none where none is made or
     * it cannot connect.
     *
     * @param  Closure(PDO): (list<list<string>>|NotGiven) $read
     * @return list<list<string>>|NotGiven
     */
    public function freshly(string $name, object $connection, Closure $read): array|NotGiven
    {
        $factory = ByName::called($this->application, 'make', self::FACTORY);
        $fresh = ByName::called($factory, 'make', $this->config($connection), $name);

        try {
            $pdo = ByName::called($fresh, 'getPdo');

            return $pdo instanceof PDO ? $read($pdo) : NotGiven::value();
        } catch (PDOException) {
            return NotGiven::value();
        } finally {
            ByName::called($fresh, 'disconnect');
        }
    }

    /** The connection by this name closed, to connect again as it is next used. */
    public function closed(string $name): void
    {
        ByName::called($this->manager, 'reconnect', $name);
    }

    /**
     * Each connection whose config reaches the database this reach does
     * pointed at its copy: in the application's config, and each the
     * manager holds closed, to connect to the copy as it is next used.
     */
    public function redirected(Reach $reach, string $copy): void
    {
        $settings = ByName::called($this->application, 'make', self::CONFIG);
        $all = ByName::called($settings, 'get', self::CONNECTIONS);
        $all = is_array($all) ? $all : [];
        $names = [];

        foreach ($all as $name => $each) {
            if (is_array($each) && $this->where($each) === $reach->where) {
                $each[LaravelConfig::DATABASE] = $copy;
                $all[$name] = $each;
                $names[] = $name;
            }
        }

        ByName::called($settings, 'set', self::CONNECTIONS, $all);

        foreach ($this->open() as $name => $connection) {
            if (in_array($name, $names, strict: true)) {
                ByName::called($connection, 'setDatabaseName', $copy);
                $this->closed($name);
            }
        }
    }

    /** @return array<mixed> */
    private function config(object $connection): array
    {
        $config = ByName::called($connection, 'getConfig');

        return is_array($config) ? $config : [];
    }

    /**
     * Each key that says where a connection reaches, in order, none where it holds none, as one text.
     *
     * @param array<mixed> $config
     */
    private function where(array $config): string
    {
        return serialize([
            ...array_fill_keys(self::REACHES, null),
            ...array_intersect_key($config, array_flip(self::REACHES)),
        ]);
    }
}
