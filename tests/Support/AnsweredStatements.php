<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function count;
use function in_array;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Statements;
use PDOException;

use function sprintf;

/**
 * A database that answers each query it knows with the rows it was given,
 * refuses every other as PDO refuses a query it cannot run, and keeps each
 * statement run on it, in order, for a test of a dialect no test can reach.
 */
final class AnsweredStatements implements Statements
{
    /** @var list<string> */
    private array $ran = [];

    /**
     * @param array<string, list<list<string>>> $answers each query's rows, by the query
     * @param list<string>                      $refused each statement that fails as it runs
     */
    public function __construct(private readonly array $answers, private readonly array $refused = [])
    {
    }

    /** @return list<list<string>> */
    public function rows(string $query): array
    {
        return array_key_exists($query, $this->answers) ? $this->answers[$query] : throw new PDOException($query);
    }

    public function digest(string $query): string
    {
        return sprintf('%d rows', count($this->rows($query)));
    }

    public function run(string $statement): void
    {
        if (in_array($statement, $this->refused, strict: true)) {
            throw new PDOException($statement);
        }

        $this->ran[] = $statement;
    }

    public function quoted(string $value): string
    {
        return sprintf("'%s'", $value);
    }

    /** @return list<string> each statement run, in order */
    public function ran(): array
    {
        return $this->ran;
    }
}
