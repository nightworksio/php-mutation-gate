<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use PDOException;

/**
 * How one kind of database lists its tables and the counters a rolled-back
 * transaction leaves where they moved, and sets those counters back
 * (ADR-0023).
 */
interface Dialect
{
    /**
     * @return list<string> each table of the database, quoted as a query names it
     *
     * @throws PDOException
     */
    public function tables(Statements $database): array;

    /**
     * @return array<string, string> each counter's next value, by its quoted name
     *
     * @throws PDOException
     */
    public function counters(Statements $database): array;

    /**
     * Each counter that stands elsewhere now set back to where it stood.
     *
     * @param array<string, string> $stood each counter's value to set back to, by its quoted name
     * @param array<string, string> $now   each counter's value now, by its quoted name
     *
     * @throws PDOException
     */
    public function restore(Statements $database, array $stood, array $now): void;
}
