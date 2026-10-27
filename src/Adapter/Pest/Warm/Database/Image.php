<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function ksort;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use PDOException;

use function sprintf;

/**
 * A test database as it stands (ADR-0023): each table's rows, as one digest
 * of them in any order, and each counter a rolled-back transaction leaves
 * moved, by its name. Two images are alike exactly where every table holds
 * the same rows and every counter stands at the same value.
 */
final readonly class Image
{
    private const string ROWS = 'SELECT * FROM %s';

    /**
     * @param array<string, string> $tables   each table's digest of its rows, by its quoted name
     * @param array<string, string> $counters each counter's value, by its quoted name
     */
    private function __construct(private array $tables, private array $counters)
    {
    }

    /**
     * The database as it stands now.
     *
     * @throws PDOException
     */
    public static function of(Statements $database, Dialect $dialect): self
    {
        $tables = [];

        foreach ($dialect->tables($database) as $table) {
            $tables[$table] = $database->digest(sprintf(self::ROWS, $table));
        }

        return self::sorted($tables, $dialect->counters($database));
    }

    /** An image a child wrote. */
    public static function read(Node $at): self
    {
        $tables = [];
        $counters = [];

        foreach ($at->field('tables')->entries() as $table => $digest) {
            $tables[sprintf('%s', $table)] = $digest->text();
        }

        foreach ($at->field('counters')->entries() as $counter => $value) {
            $counters[sprintf('%s', $counter)] = $value->text();
        }

        return self::sorted($tables, $counters);
    }

    public function written(): Json
    {
        $tables = [];
        $counters = [];

        foreach ($this->tables as $table => $digest) {
            $tables[] = Member::of($table, $digest);
        }

        foreach ($this->counters as $counter => $value) {
            $counters[] = Member::of($counter, $value);
        }

        return Json::object(
            Member::of('tables', Json::object(...$tables)),
            Member::of('counters', Json::object(...$counters)),
        );
    }

    /** @return array<string, string> each counter's value, by its quoted name */
    public function counters(): array
    {
        return $this->counters;
    }

    /** Whether the other image shows the database as this one does. */
    public function isAlike(self $other): bool
    {
        return $this->tables === $other->tables && $this->counters === $other->counters;
    }

    /**
     * @param array<string, string> $tables
     * @param array<string, string> $counters
     */
    private static function sorted(array $tables, array $counters): self
    {
        ksort($tables);
        ksort($counters);

        return new self($tables, $counters);
    }
}
