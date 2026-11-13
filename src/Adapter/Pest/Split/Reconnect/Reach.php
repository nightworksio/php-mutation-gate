<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use Closure;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where a connection the application holds reaches, as its config says
 * (ADR-0147): its driver, how to connect to its database again, the name its
 * config gives that database, whether it reads and writes apart, what tells
 * two configs that reach one database alike, and how to connect to another
 * database on its server.
 *
 * @internal
 */
final readonly class Reach
{
    /** @param Closure(string): (Connection|NotGiven) $elsewhere */
    public function __construct(
        public Driver|NotGiven $driver,
        public Connection|NotGiven $connection,
        public string $database,
        public bool $apart,
        public string $where,
        private Closure $elsewhere,
    ) {
    }

    /** Whether it reaches an SQLite database held in memory, which forks with the process. */
    public function inMemory(): bool
    {
        return $this->driver === Driver::Sqlite && ! $this->connection instanceof Connection;
    }

    /** A connection to the database by this name on the same server; none where the config makes none. */
    public function elsewhere(string $database): Connection|NotGiven
    {
        return ($this->elsewhere)($database);
    }
}
