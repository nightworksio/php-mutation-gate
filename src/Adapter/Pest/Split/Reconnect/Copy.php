<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use PDOException;

/**
 * One database a stream's parent copied for a child (ADR-0147): the name,
 * or SQLite's path, the child connects to, and how the parent reaches the
 * server again to drop it once the child ended.
 *
 * @internal
 */
final readonly class Copy
{
    public function __construct(public string $database, private Connection $admin, private Copier $copier)
    {
    }

    public function dropped(): void
    {
        try {
            $this->copier->drop($this->admin, $this->database);
        } catch (PDOException) {
            return;
        }
    }
}
