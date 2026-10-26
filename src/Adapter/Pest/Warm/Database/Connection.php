<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use PDO;
use PDOException;

use function sprintf;
use function str_replace;

/**
 * How to reach one test database a suite's tests ran on, as Laravel's config
 * of the connection says (ADR-0023): PDO's data source name, the user and the
 * password, the PDO attributes the config sets, and the schemas PostgreSQL
 * searches. A warm worker's children each open their own connection with
 * it, and close it as they end, so the worker never holds one it forks with.
 */
final readonly class Connection
{
    private const string SEARCH = 'SET search_path TO %s';

    /**
     * @param array<int, int|string|bool> $options each PDO attribute the config sets, by its number
     * @param list<string>                $search  the schemas PostgreSQL searches, where the config names them
     */
    private function __construct(
        private Driver $driver,
        private string $dsn,
        private string $username,
        private string $password,
        private array $options,
        private array $search,
    ) {
    }

    /**
     * @param array<int, int|string|bool> $options each PDO attribute the config sets, by its number
     * @param list<string>                $search  the schemas PostgreSQL searches, where the config names them
     */
    public static function of(
        Driver $driver,
        string $dsn,
        string $username,
        string $password,
        array $options,
        array $search,
    ): self {
        return new self($driver, $dsn, $username, $password, $options, $search);
    }

    /** A connection a child wrote. */
    public static function read(Node $at): self
    {
        $options = [];

        foreach ($at->field('options')->items() as $option) {
            $value = $option->field('value');
            $options[$option->field('attribute')->integer()] = match ($value->kind()) {
                Kind::Integer => $value->integer(),
                Kind::Boolean => $value->boolean(),
                Kind::Text, Kind::Number, Kind::Map, Kind::List, Kind::Empty, Kind::Null, Kind::Nothing
                    => $value->text(),
            };
        }

        return new self(
            Driver::from($at->field('driver')->text()),
            $at->field('dsn')->text(),
            $at->field('username')->text(),
            $at->field('password')->text(),
            $options,
            array_map(static fn(Node $schema): string => $schema->text(), $at->field('search')->items()),
        );
    }

    public function written(): Json
    {
        $options = [];

        foreach ($this->options as $attribute => $value) {
            $options[] = Json::object(Member::of('attribute', $attribute), Member::of('value', $value));
        }

        return Json::object(
            Member::of('driver', $this->driver->value),
            Member::of('dsn', $this->dsn),
            Member::of('username', $this->username),
            Member::of('password', $this->password),
            Member::of('options', Json::items(...$options)),
            Member::of('search', Json::items(...$this->search)),
        );
    }

    public function dialect(): Dialect
    {
        return $this->driver->dialect();
    }

    /**
     * A connection opened to the database, searching the schemas the config names.
     *
     * @throws PDOException
     */
    public function opened(): Statements
    {
        $statements = PdoStatements::over(new PDO($this->dsn, $this->username, $this->password, $this->options));

        if ($this->search !== []) {
            $statements->run(sprintf(self::SEARCH, implode(', ', array_map($this->quoted(...), $this->search))));
        }

        return $statements;
    }

    private function quoted(string $schema): string
    {
        return sprintf('"%s"', str_replace('"', '""', $schema));
    }
}
