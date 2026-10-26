<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_map;
use function array_values;

use Closure;

use function count;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

use function in_array;

use PDO;

use function spl_autoload_functions;
use function sprintf;

/**
 * The stand-in for Laravel's testing (tests/Fixtures/Laravel), loaded into
 * the suite's own process where a test asks for it: an application serving
 * one SQLite connection, and the state RefreshDatabase keeps, cleared.
 */
final readonly class LaravelTesting
{
    /** The class RefreshDatabase asks for before its first transaction. */
    public const string TRANSACTIONS = 'Illuminate\Foundation\Testing\DatabaseTransactionsManager';

    /**
     * An application serving one connection, outside any transaction, to a
     * migrated SQLite database in a file of its own, not yet migrated as far
     * as RefreshDatabase knows: the file.
     */
    public static function serving(): string
    {
        $file = sprintf('%s/testing.sqlite', Scratch::directory());
        self::connected(Databases::made(sprintf('sqlite:%s', $file), 'CREATE TABLE users (id INTEGER PRIMARY KEY)'), $file);

        return $file;
    }

    /** An application serving this open connection to the SQLite database in this file. */
    public static function connected(PDO $pdo, string $file): void
    {
        self::loaded();
        RefreshDatabaseState::forget();
        Container::serving(new DatabaseManager(['sqlite' => new Connection(['driver' => 'sqlite', 'database' => $file], $pdo)]));
    }

    /** No application, and RefreshDatabase not migrated. */
    public static function left(): void
    {
        self::loaded();
        RefreshDatabaseState::forget();
        Container::leaving();
    }

    /** Runs this, then forgets each autoloader it registered: how many it did. */
    public static function spiesOf(Closure $run): int
    {
        $before = spl_autoload_functions();

        try {
            $run();
        } finally {
            $registered = array_values(array_filter(
                spl_autoload_functions(),
                static fn(callable $loader): bool => ! in_array($loader, $before, strict: true),
            ));
            array_map(spl_autoload_unregister(...), $registered);
        }

        return count($registered);
    }

    private static function loaded(): void
    {
        require_once Tree::at('tests/Fixtures/Laravel/Laravel.php');
    }
}
