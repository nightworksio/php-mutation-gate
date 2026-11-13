<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_any;
use function array_map;
use function array_values;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\PdoStatements;
use NightWorksIO\MutationGate\Core\NotGiven;
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
    public const string NONE = '0';

    private function __construct(private PDO $pdo)
    {
    }

    public static function of(PDO $pdo): self|NotGiven
    {
        return $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION ? new self($pdo) : NotGiven::value();
    }

    /**
     * Whether any of these counts anything, each read as `counts` reads it.
     *
     * @param list<list<string>> $checks
     */
    public function countsAny(array $checks): bool
    {
        return array_any($checks, fn(array $counts): bool => $this->counts($counts));
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

        return $rows instanceof NotGiven || Cell::of($rows, 0, 0) !== self::NONE;
    }

    /**
     * The rows of the first of these queries that the database answers with
     * any; none where it answers none of them so.
     *
     * @param  list<string>             $queries
     * @return list<list<string>>|NotGiven
     */
    public function first(array $queries): array|NotGiven
    {
        foreach ($queries as $query) {
            $rows = $this->rows($query);

            if (! $rows instanceof NotGiven && $rows !== []) {
                return $rows;
            }
        }

        return NotGiven::value();
    }

    /**
     * @return list<list<string>>|NotGiven each row, a value that is none read as empty text; none where the query fails
     */
    private function rows(string $query): array|NotGiven
    {
        try {
            $statement = $this->pdo->query($query, PDO::FETCH_NUM);
        } catch (PDOException) {
            return NotGiven::value();
        }

        $rows = [];

        foreach ($statement === false ? [] : $statement as $row) {
            $rows[] = array_map(PdoStatements::text(...), is_array($row) ? array_values($row) : []);
        }

        return $rows;
    }
}
