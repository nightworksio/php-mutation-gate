<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use PDOException;

/**
 * How one kind of database tells what a session holds that a copy of the
 * database would lack, and copies and drops a database (ADR-0147).
 *
 * @internal
 */
interface Copier
{
    /** Whether the session holds state a copy would lack: true where the database cannot tell. */
    public function holdsState(Session $session): bool;

    /**
     * Each setting the session holds, its name then its value, which a
     * fresh session of the same config holds alike where nothing set one;
     * none where the database cannot tell.
     *
     * @return list<list<string>>|null
     */
    public function settings(Session $session): ?array;

    /**
     * A copy of the database this config names, as its committed state
     * stands, named by this token; none where it holds what no copy
     * carries. Nothing of a copy that failed is left.
     *
     * @param array<mixed> $config the connection's config, as Laravel holds it
     *
     * @throws PDOException
     */
    public function copied(Connection $original, array $config, string $token, string $directory): ?Copy;

    /** @throws PDOException */
    public function drop(Connection $admin, string $copy): void;
}
