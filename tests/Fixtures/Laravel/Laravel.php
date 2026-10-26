<?php

declare(strict_types=1);

// The part of Laravel's testing a warm worker's child reads and sets, by the
// names Laravel gives it, for the tests of the warm workers' test databases:
// RefreshDatabase's state, the container's current application, its
// database manager and the connections it holds open.

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
    }
}

namespace Illuminate\Database {
    use PDO;

    /** A connection Laravel holds open, outside any transaction. */
    final readonly class Connection
    {
        /** @param array<string, string> $config */
        public function __construct(private array $config, private PDO $pdo)
        {
        }

        /** @return array<string, string> */
        public function getConfig(): array
        {
            return $this->config;
        }

        public function getPdo(): PDO
        {
            return $this->pdo;
        }

        public function transactionLevel(): int
        {
            return $this->pdo->inTransaction() ? 1 : 0;
        }
    }

    /** Laravel's database manager, holding these connections open. */
    final readonly class DatabaseManager
    {
        /** @param array<string, Connection> $connections */
        public function __construct(private array $connections)
        {
        }

        /** @return array<string, Connection> */
        public function getConnections(): array
        {
            return $this->connections;
        }
    }
}

namespace Illuminate\Container {
    use Illuminate\Database\DatabaseManager;

    /** The container, whose current application binds the database manager. */
    final class Container
    {
        /** @var list<DatabaseManager> the manager the current application binds, where there is one */
        private static array $managers = [];

        /** The current application, binding this manager as `db`. */
        public static function serving(DatabaseManager $manager): void
        {
            self::$managers = [$manager];
        }

        public static function getInstance(): self
        {
            return new self();
        }

        public function bound(string $abstract): bool
        {
            return $abstract === 'db' && self::$managers !== [];
        }

        public function make(): DatabaseManager
        {
            return self::$managers[0];
        }
    }
}
