<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_map;
use function array_values;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PdoStatements;
use PDO;
use PDOException;

/**
 * A database session the application holds, read as it stands (ADR-0147):
 * each query runs on the application's own connection, whose attributes
 * stay as they are. A connection that reports an error other than by an
 * exception is not read, as a query that fails there would warn in the test.
 *
 * @internal
 */
final readonly class Session
{
    /** What a count reads as where nothing is counted. */
    private const string NONE = '0';

    private function __construct(private PDO $pdo)
    {
    }

    public static function of(PDO $pdo): ?self
    {
        return $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION ? new self($pdo) : null;
    }

    /**
     * Whether any of these counts anything, each read as `counts` reads it.
     *
     * @param list<list<string>> $checks
     */
    public function countsAny(array $checks): bool
    {
        foreach ($checks as $counts) {
            if ($this->counts($counts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the first of these counts that the database answers counts
     * anything; true where it answers none of them.
     *
     * @param list<string> $counts
     */
    public function counts(array $counts): bool
    {
        $rows = $this->first($counts);

        return $rows === null || ($rows[0][0] ?? null) !== self::NONE;
    }

    /**
     * The rows of the first of these queries that the database answers with
     * any; none where it answers none of them so.
     *
     * @param  list<string>             $queries
     * @return list<list<string>>|null
     */
    public function first(array $queries): ?array
    {
        foreach ($queries as $query) {
            $rows = $this->rows($query);

            if ($rows !== null && $rows !== []) {
                return $rows;
            }
        }

        return null;
    }

    /** @return list<list<string>>|null each row, a value that is none read as empty text; none where the query fails */
    private function rows(string $query): ?array
    {
        try {
            $statement = $this->pdo->query($query, PDO::FETCH_NUM);
        } catch (PDOException) {
            return null;
        }

        $rows = [];

        foreach ($statement === false ? [] : $statement as $row) {
            $rows[] = array_map(PdoStatements::text(...), is_array($row) ? array_values($row) : []);
        }

        return $rows;
    }
}
