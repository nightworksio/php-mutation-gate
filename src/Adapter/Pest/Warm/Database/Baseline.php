<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function array_all;
use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use PDOException;

/**
 * Every test database a warm worker's child found freshly migrated, as it
 * stood then (ADR-0023). A child of the worker starts on them as migrated
 * only while each still stands so once its counters are set back.
 *
 * @internal
 */
final readonly class Baseline
{
    /** @param non-empty-list<Snapshot> $snapshots */
    private function __construct(private array $snapshots)
    {
    }

    /**
     * The databases as they stand now, each read over a connection of its
     * own, as every later check of them is; none where there are none, or one
     * cannot be read.
     *
     * @param list<Connection> $connections
     */
    public static function taken(array $connections): self|NotGiven
    {
        $snapshots = [];

        try {
            foreach ($connections as $connection) {
                $snapshots[] = Snapshot::of($connection, Image::of($connection->opened(), $connection->dialect()));
            }
        } catch (PDOException) {
            return NotGiven::value();
        }

        return $snapshots === [] ? NotGiven::value() : new self($snapshots);
    }

    /** A baseline a child wrote; none where it holds no database. */
    public static function read(Node $at): self|NotGiven
    {
        $snapshots = array_map(Snapshot::read(...), $at->items());

        return $snapshots === [] ? NotGiven::value() : new self($snapshots);
    }

    public function written(): Json
    {
        return Json::items(...array_map(static fn(Snapshot $snapshot): Json => $snapshot->written(), $this->snapshots));
    }

    /** Whether every database still stands as it did, once its counters are set back. */
    public function holds(): bool
    {
        return array_all($this->snapshots, static fn(Snapshot $snapshot): bool => $snapshot->holds());
    }
}
