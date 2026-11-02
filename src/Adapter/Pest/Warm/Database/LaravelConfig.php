<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function array_is_list;
use function array_key_exists;
use function explode;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\NotGiven;

use function reset;
use function trim;

/**
 * A connection's config as Laravel's database manager holds it, read for how
 * to reach its database again (ADR-0023): its driver, its host and port, or
 * MySQL's socket, its database, its user and password, the PDO attributes it
 * sets and PostgreSQL's schemas to search. Laravel lists the hosts a
 * connection may use where it has several; the first is the one read.
 *
 * @internal
 */
final readonly class LaravelConfig
{
    /** @param array<mixed> $config a connection's config, as Laravel holds it */
    public static function connection(array $config): Connection|NotGiven
    {
        $driver = Driver::tryFrom(self::text($config, 'driver'));
        $dsn = $driver instanceof Driver ? $driver->dsn(
            self::text($config, 'host'),
            self::text($config, 'port'),
            self::text($config, 'database'),
            self::text($config, 'unix_socket'),
            self::text($config, 'sslmode'),
        ) : NotGiven::value();

        return $driver instanceof Driver && is_string($dsn) ? Connection::of(
            $driver,
            $dsn,
            self::text($config, 'username'),
            self::text($config, 'password'),
            self::options(self::entry($config, 'options')),
            $driver === Driver::Postgres ? self::schemas(self::searched($config)) : [],
        ) : NotGiven::value();
    }

    /** @return array<int, int|string|bool> */
    private static function options(mixed $options): array
    {
        $kept = [];

        foreach (is_array($options) ? $options : [] as $attribute => $value) {
            if (is_int($attribute) && (is_int($value) || is_string($value) || is_bool($value))) {
                $kept[$attribute] = $value;
            }
        }

        return $kept;
    }

    /** @return list<string> */
    private static function schemas(mixed $search): array
    {
        $names = match (true) {
            is_string($search) => explode(',', $search),
            is_array($search) && array_is_list($search) => $search,
            default => [],
        };
        $schemas = [];

        foreach ($names as $name) {
            $schema = is_string($name) ? trim($name, " \"'") : '';
            $schemas = $schema === '' ? $schemas : [...$schemas, $schema];
        }

        return $schemas;
    }

    /**
     * A setting as text: the first of a list, as Laravel's config lists hosts
     * a connection may use; nothing where it is unset or not text.
     *
     * @param array<mixed> $config
     */
    private static function text(array $config, string $key): string
    {
        $value = self::entry($config, $key);
        $value = is_array($value) ? reset($value) : $value;

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /**
     * The schemas a PostgreSQL connection's config says to search, by the
     * key Laravel reads them from, or by the older key it read before.
     *
     * @param array<mixed> $config
     */
    private static function searched(array $config): mixed
    {
        return array_key_exists('search_path', $config) ? $config['search_path'] : self::entry($config, 'schema');
    }

    /**
     * A setting of a config, or none where it is unset.
     *
     * @param array<mixed> $config
     */
    private static function entry(array $config, string $key): mixed
    {
        return array_key_exists($key, $config) ? $config[$key] : NotGiven::value();
    }
}
