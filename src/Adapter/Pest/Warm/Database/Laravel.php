<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm\Database;

use function array_values;
use function class_exists;

use Closure;

use function is_array;
use function is_callable;
use function is_object;
use function is_string;

use NightWorksIO\MutationGate\Core\NotGiven;
use ReflectionProperty;

use function spl_autoload_register;

/**
 * What a warm worker's child reads of Laravel's testing, and tells it, by
 * name, since the gate does not require Laravel (ADR-0023):
 * - `RefreshDatabaseState::$migrated`, which `RefreshDatabase` sets once it
 *   has migrated the test database, and clears where a test left its
 *   transaction, so the next test migrates again;
 * - the moment `RefreshDatabase` asks for its transactions' manager the first
 *   time, which it does after it migrated and before its first transaction;
 * - the connections Laravel's database manager holds open then, and the
 *   config each was made from (see LaravelConfig).
 */
final readonly class Laravel
{
    private const string STATE = 'Illuminate\Foundation\Testing\RefreshDatabaseState';

    private const string MIGRATED = 'migrated';

    private const string TRANSACTIONS = 'Illuminate\Foundation\Testing\DatabaseTransactionsManager';

    private const string CONTAINER = 'Illuminate\Container\Container';

    /** Whether `RefreshDatabase` holds its test database migrated; not where Laravel's testing is not installed. */
    public static function migrated(): bool
    {
        $state = self::loaded(self::STATE);

        return is_string($state) && new ReflectionProperty($state, self::MIGRATED)->getValue() === true;
    }

    /** `RefreshDatabase` told its test database is migrated, where Laravel's testing is installed. */
    public static function markMigrated(): void
    {
        $state = self::loaded(self::STATE);

        if (is_string($state)) {
            new ReflectionProperty($state, self::MIGRATED)->setValue(objectOrValue: null, value: true);
        }
    }

    /**
     * Calls this as `RefreshDatabase` asks for its transactions' manager the
     * first time, before the manager loads: the class loads once, so it is
     * called once at most.
     *
     * @param Closure(): void $then
     */
    public static function beforeFirstTransaction(Closure $then): void
    {
        spl_autoload_register(static function (string $class) use ($then): void {
            if ($class === self::TRANSACTIONS) {
                $then();
            }
        }, prepend: true);
    }

    /**
     * How to reach again the database of each connection Laravel's database
     * manager holds open; none where there is no application, where a
     * connection is inside a transaction, or where one's database is held in
     * memory or is of a kind the worker cannot tell.
     *
     * @return list<Connection>|NotGiven
     */
    public static function connections(): array|NotGiven
    {
        $connections = [];

        foreach (self::open() as $open) {
            $reached = self::reached($open);

            if ($reached instanceof NotGiven) {
                return $reached;
            }

            $connections[] = $reached;
        }

        return $connections === [] ? NotGiven::value() : $connections;
    }

    /** @return list<mixed> each connection the current application's database manager holds open */
    private static function open(): array
    {
        $application = self::called(self::CONTAINER, 'getInstance');
        $manager = self::called($application, 'bound', 'db') === true
            ? self::called($application, 'make', 'db')
            : null;
        $open = self::called($manager, 'getConnections');

        return is_array($open) ? array_values($open) : [];
    }

    /**
     * How to reach again the database of a connection Laravel holds open;
     * none where it is inside a transaction or its database cannot be reached.
     */
    private static function reached(mixed $open): Connection|NotGiven
    {
        $config = self::called($open, 'getConfig');
        $connection = LaravelConfig::connection(is_array($config) ? $config : []);

        return self::called($open, 'transactionLevel') === 0 ? $connection : NotGiven::value();
    }

    /**
     * The class by this name, loaded where it is not yet; none where there is no such class.
     *
     * @return class-string|NotGiven
     */
    private static function loaded(string $name): string|NotGiven
    {
        return class_exists($name) ? $name : NotGiven::value();
    }

    /** What a method of an object, or a static one of a class, answers; nothing where there is no such method. */
    private static function called(mixed $on, string $method, mixed ...$arguments): mixed
    {
        $callable = [$on, $method];

        return (is_object($on) || is_string($on)) && is_callable($callable) ? $callable(...$arguments) : null;
    }
}
