<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use PDOException;

/**
 * A connection to a test database, as a warm worker's child reads and resets
 * it (ADR-0023): each row a query returns, every value as text, one digest
 * of every row a query returns, and statements that return nothing.
 *
 * @internal
 */
interface Statements
{
    /**
     * @return list<list<string>> each row, its values in the order the query names them, none read as empty text
     *
     * @throws PDOException
     */
    public function rows(string $query): array;

    /**
     * One digest of every row the query returns, in any order: alike for two
     * sets of rows exactly where they hold the same rows, a value that is none
     * apart from any text.
     *
     * @throws PDOException
     */
    public function digest(string $query): string;

    /** @throws PDOException */
    public function run(string $statement): void;

    /** A value as a literal the database reads back as that value. */
    public function quoted(string $value): string;
}
