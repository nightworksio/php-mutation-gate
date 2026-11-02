<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use PDOException;

/**
 * One test database as it stood once freshly migrated, with how to reach it
 * again (ADR-0023).
 *
 * @internal
 */
final readonly class Snapshot
{
    private function __construct(private Connection $connection, private Image $image)
    {
    }

    public static function of(Connection $connection, Image $image): self
    {
        return new self($connection, $image);
    }

    public static function read(Node $at): self
    {
        return new self(Connection::read($at->field('connection')), Image::read($at->field('image')));
    }

    public function written(): Json
    {
        return Json::object(
            Member::of('connection', $this->connection->written()),
            Member::of('image', $this->image->written()),
        );
    }

    /**
     * Whether the database stands as it did once its counters are set back:
     * every table holding the same rows, read over a connection of its own.
     * A database that cannot be reached stands elsewhere.
     */
    public function holds(): bool
    {
        try {
            return $this->holdsOn($this->connection->opened());
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Whether the database stands as it did, read over this connection, once
     * each counter that moved is set back.
     *
     * @throws PDOException
     */
    public function holdsOn(Statements $database): bool
    {
        $dialect = $this->connection->dialect();
        $dialect->restore($database, $this->image->counters(), $dialect->counters($database));

        return Image::of($database, $dialect)->isAlike($this->image);
    }
}
