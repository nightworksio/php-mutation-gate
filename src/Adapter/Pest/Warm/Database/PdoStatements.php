<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function array_map;
use function array_values;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_resource;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use PDO;

use function sort;
use function stream_get_contents;
use function strval;

/**
 * Statements over a PDO connection: every value a row holds read as text, a
 * boolean as 1 or 0 and a stream, as PostgreSQL hands a binary column, as its
 * bytes. A digest marks each value as none, a NUL, or as text by the
 * digest of its text, which holds neither a NUL nor what joins values, so
 * two rows digest alike exactly where they hold the same values.
 */
final readonly class PdoStatements implements Statements
{
    /** Joins a row's values, and its rows' digests. */
    private const string BETWEEN = "\x1f";

    private function __construct(private PDO $pdo)
    {
    }

    public static function over(PDO $pdo): self
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new self($pdo);
    }

    /** @return list<list<string>> */
    public function rows(string $query): array
    {
        return array_map(
            static fn(array $row): array => array_map(strval(...), $row),
            $this->read($query),
        );
    }

    public function digest(string $query): string
    {
        $rows = array_map($this->digestOf(...), $this->read($query));
        sort($rows);

        return Digest::sha256Of(implode(self::BETWEEN, $rows))->value();
    }

    public function run(string $statement): void
    {
        $this->pdo->exec($statement);
    }

    public function quoted(string $value): string
    {
        $quoted = $this->pdo->quote($value);

        return is_string($quoted) ? $quoted : $value;
    }

    /**
     * A value PDO hands, other than none, as text: a boolean as 1 or 0, a
     * stream as its bytes, and anything PDO does not hand as empty text.
     */
    public static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            is_resource($value) => (string) stream_get_contents($value),
            default => '',
        };
    }

    /** @return list<list<string|null>> */
    private function read(string $query): array
    {
        $statement = $this->pdo->query($query, PDO::FETCH_NUM);
        $rows = [];

        foreach ($statement === false ? [] : $statement as $row) {
            $rows[] = $this->texts(is_array($row) ? array_values($row) : []);
        }

        return $rows;
    }

    /**
     * @param  list<mixed>         $values
     * @return list<string|null>
     */
    private function texts(array $values): array
    {
        $texts = [];

        foreach ($values as $value) {
            $texts[] = $value === null ? null : self::text($value);
        }

        return $texts;
    }

    /** @param list<string|null> $row */
    private function digestOf(array $row): string
    {
        return Digest::sha256Of(implode(self::BETWEEN, array_map($this->marked(...), $row)))->value();
    }

    private function marked(mixed $value): string
    {
        return is_string($value) ? Digest::sha256Of($value)->value() : Bytes::NUL;
    }
}
