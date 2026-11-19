<?php

declare(strict_types=1);

// The part of Laravel a warm worker's child and a reconnecting stream read
// and set, by the names Laravel gives it, for the tests of the warm workers'
// test databases and of the databases a stream copies: RefreshDatabase's
// state, the container's current application, its database manager, the
// connections it holds open, its connection factory and its config.

namespace Illuminate\Foundation\Testing {
    /** RefreshDatabase's state: whether the test database is migrated. */
    final class RefreshDatabaseState
    {
        private static bool $migrated = false;

        public static function isMigrated(): bool
        {
            return self::$migrated;
        }

        /** Set as RefreshDatabase sets it once it has migrated. */
        public static function migrate(): void
        {
            self::$migrated = true;
        }

        /** Cleared, as in a process that has not migrated yet. */
        public static function forget(): void
        {
            self::$migrated = false;
        }
    }
}

namespace Illuminate\Database {
    use PDO;

    /** A connection Laravel holds, its PDO dropped once it is told to reconnect, and made again as it is next used. */
    final class Connection
    {
        /** @param array<string, string> $config */
        public function __construct(private array $config, private PDO|false $pdo)
        {
        }

        /** @return array<string, string> */
        public function getConfig(): array
        {
            return $this->config;
        }

        public function getPdo(): PDO
        {
            if ($this->pdo === false) {
                $this->pdo = new PDO(\sprintf('sqlite:%s', $this->config['database'] ?? ''), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }

            return $this->pdo;
        }

        /** The PDO, where the connection is connected; false where it connects again as it is next used. */
        public function getRawPdo(): PDO|false
        {
            return $this->pdo;
        }

        public function transactionLevel(): int
        {
            return $this->pdo !== false && $this->pdo->inTransaction() ? 1 : 0;
        }

        public function setDatabaseName(string $database): void
        {
            $this->config['database'] = $database;
        }

        public function disconnect(): void
        {
            $this->pdo = false;
        }
    }

    /** Laravel's database manager, holding these connections open. */
    final class DatabaseManager
    {
        /** @var list<string> each connection told to reconnect, by its name, in turn */
        public private(set) array $reconnected = [];

        /** @param array<string, Connection> $connections */
        public function __construct(private readonly array $connections)
        {
        }

        /** @return array<string, Connection> */
        public function getConnections(): array
        {
            return $this->connections;
        }

        public function reconnect(string $name): void
        {
            $this->reconnected[] = $name;
            $this->connections[$name]->disconnect();
        }
    }
}

namespace Illuminate\Database\Connectors {
    use Illuminate\Database\Connection;

    /** Laravel's connection factory: a fresh SQLite connection of a config. */
    final class ConnectionFactory
    {
        /** @param array<string, string> $config */
        public function make(array $config): Connection
        {
            return new Connection($config, pdo: false);
        }
    }
}

namespace Illuminate\Config {
    /** Laravel's config, read and set by dotted keys. */
    final class Repository
    {
        /** @param array<string, mixed> $items */
        public function __construct(private array $items)
        {
        }

        public function get(string $key): mixed
        {
            return $this->items[$key] ?? null;
        }

        public function set(string $key, mixed $value): void
        {
            $this->items[$key] = $value;
        }
    }
}

namespace Illuminate\Container {
    use Illuminate\Config\Repository;
    use Illuminate\Database\Connectors\ConnectionFactory;
    use Illuminate\Database\DatabaseManager;

    /**
     * The container, whose current application binds the database manager
     * as `db`, its connection factory as `db.factory` and its config, which
     * holds each connection's config under `database.connections`.
     */
    final class Container
    {
        /** @var array<string, object> what the current application binds, by name, where there is one */
        private static array $bound = [];

        /** The current application, binding this manager, a connection factory and the config of its connections. */
        public static function serving(DatabaseManager $manager): void
        {
            $connections = [];

            foreach ($manager->getConnections() as $name => $connection) {
                $connections[$name] = $connection->getConfig();
            }

            self::$bound = [
                'db' => $manager,
                'db.factory' => new ConnectionFactory(),
                'config' => new Repository(['database.connections' => $connections]),
            ];
        }

        /** No current application, as before Laravel boots one. */
        public static function leaving(): void
        {
            self::$bound = [];
        }

        public static function getInstance(): self
        {
            return new self();
        }

        public function bound(string $abstract): bool
        {
            return \array_key_exists($abstract, self::$bound);
        }

        public function make(string $abstract): object
        {
            return self::$bound[$abstract];
        }
    }
}
