<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\ByName;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Laravel;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\LaravelConfig;
use PDO;
use PDOException;

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

    /** The keys of a config that say which database it reaches. */
    private const array REACHES = ['driver', 'host', 'port', 'unix_socket', LaravelConfig::DATABASE];

    private function __construct(private object $application, private object $manager)
    {
    }

    /** The current application's databases; none where there is no application, or it binds no database manager. */
    public static function current(): ?self
    {
        $application = ByName::called(Laravel::CONTAINER, 'getInstance');
        $bound = ByName::called($application, 'bound', self::MANAGER) === true
            && ByName::called($application, 'bound', self::FACTORY) === true
            && ByName::called($application, 'bound', self::CONFIG) === true;
        $manager = $bound ? ByName::called($application, 'make', self::MANAGER) : null;

        return is_object($application) && is_object($manager) ? new self($application, $manager) : null;
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

    /** @return array<mixed> */
    public function config(object $connection): array
    {
        $config = ByName::called($connection, 'getConfig');

        return is_array($config) ? $config : [];
    }

    /** The connection's PDO, where it is connected; none where it would connect as it is next used. */
    public function pdo(object $connection): ?PDO
    {
        $pdo = ByName::called($connection, 'getRawPdo');

        return $pdo instanceof PDO ? $pdo : null;
    }

    /** Whether the connection is inside a transaction, by Laravel's count or by PDO's own. */
    public function inTransaction(object $connection, PDO $pdo): bool
    {
        $level = ByName::called($connection, 'transactionLevel');

        return ! is_int($level) || $level !== 0 || $pdo->inTransaction();
    }

    /**
     * What this reads of a fresh connection of this config, made as the
     * manager makes one, closed once read; none where none is made or it
     * cannot connect.
     *
     * @param  array<mixed>                             $config
     * @param  callable(PDO): (list<list<string>>|null) $read
     * @return list<list<string>>|null
     */
    public function freshly(string $name, array $config, callable $read): ?array
    {
        $factory = ByName::called($this->application, 'make', self::FACTORY);
        $fresh = ByName::called($factory, 'make', $config, $name);

        try {
            $pdo = ByName::called($fresh, 'getPdo');

            return $pdo instanceof PDO ? $read($pdo) : null;
        } catch (PDOException) {
            return null;
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
     * Each connection whose config reaches the database this config does
     * pointed at its copy: in the application's config, and each the
     * manager holds closed, to connect to the copy as it is next used.
     *
     * @param array<mixed> $config
     */
    public function redirected(array $config, string $copy): void
    {
        $settings = ByName::called($this->application, 'make', self::CONFIG);
        $all = ByName::called($settings, 'get', self::CONNECTIONS);
        $all = is_array($all) ? $all : [];
        $names = [];

        foreach ($all as $name => $each) {
            if (is_array($each) && self::reachesAlike($each, $config)) {
                $all[$name][LaravelConfig::DATABASE] = $copy;
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

    /**
     * @param array<mixed> $one
     * @param array<mixed> $other
     */
    private static function reachesAlike(array $one, array $other): bool
    {
        foreach (self::REACHES as $key) {
            $mine = array_key_exists($key, $one) ? $one[$key] : null;
            $theirs = array_key_exists($key, $other) ? $other[$key] : null;

            if ($mine !== $theirs) {
                return false;
            }
        }

        return true;
    }
}
