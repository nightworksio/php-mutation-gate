<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_key_exists;
use function array_keys;
use function array_values;

use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Unguessable;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Core\NotGiven;
use PDOException;

use function serialize;

/**
 * The databases a stream's parent copied for one child (ADR-0147). A test
 * that holds descriptors the stream did not hold as it started forks where
 * each connection the application holds open is to a database the gate
 * copies, outside a transaction, not read and written apart, with no state
 * its session keeps that a copy would lack and every setting a fresh
 * connection of its config holds; and where, with each of them closed, the
 * process holds no descriptor a child would share. Each database is then
 * copied as its committed state stands, the child points each connection
 * to it at its copy, and the parent drops the copies once the child ended.
 * An SQLite database held in memory forks with the process, and is left as
 * it is.
 *
 * @internal
 */
final readonly class Copies
{
    /** How many random bytes name a copy. */
    private const int TOKEN = 8;

    /** @param list<array{Reach, Copy}> $copies where each copied database is reached, and its copy */
    private function __construct(private LaravelDatabases|NotGiven $databases, private array $copies)
    {
    }

    public static function none(): self
    {
        return new self(NotGiven::value(), []);
    }

    /**
     * The test's databases copied, each connection to them closed; none
     * where any of that cannot be done, the connections then closed or left
     * as they were.
     */
    public static function taken(Descriptors $start, string $directory): self|NotGiven
    {
        $databases = LaravelDatabases::current();
        $held = $databases instanceof LaravelDatabases ? HeldConnections::of($databases) : NotGiven::value();

        if (! $databases instanceof LaravelDatabases || $held instanceof NotGiven || $held === []) {
            return NotGiven::value();
        }

        foreach (array_keys($held) as $name) {
            $databases->closed($name);
        }

        return Descriptors::held()->shares($start) ? NotGiven::value() : self::copied($databases, $held, $directory);
    }

    /**
     * The databases copied for a child forked now: none where the process
     * holds no descriptor a child would share; NotGiven where it does and
     * the stream does not reconnect, or they cannot be taken.
     */
    public static function atFork(Descriptors $start, bool $reconnect, string $directory): self|NotGiven
    {
        return match (true) {
            ! Descriptors::held()->shares($start) => self::none(),
            $reconnect => self::taken($start, $directory),
            default => NotGiven::value(),
        };
    }

    /** Each connection to a copied database pointed at its copy, in the child, before its mutant runs. */
    public function reconnected(): void
    {
        $databases = $this->databases;

        if (! $databases instanceof LaravelDatabases) {
            return;
        }

        foreach ($this->copies as [$reach, $copy]) {
            $databases->redirected($reach, $copy->database);
        }
    }

    /** Each copy dropped, in the parent, once the child ended. */
    public function dropped(): void
    {
        foreach ($this->copies as [, $copy]) {
            $copy->dropped();
        }
    }

    /**
     * The databases these connections reach, each copied once; none where
     * one cannot be, every copy made then dropped.
     *
     * @param array<string, array{Reach, Driver, Connection}> $held
     */
    private static function copied(LaravelDatabases $databases, array $held, string $directory): self|NotGiven
    {
        $copies = [];

        foreach ($held as [$reach, $driver, $reached]) {
            $key = serialize($reached);
            $copy = array_key_exists($key, $copies)
                ? $copies[$key][1]
                : self::copyOf($driver, $reached, $reach, $directory);

            if (! $copy instanceof Copy) {
                new self($databases, array_values($copies))->dropped();

                return $copy;
            }

            $copies[$key] = [$reach, $copy];
        }

        return new self($databases, array_values($copies));
    }

    /** A copy of the database a connection reaches; none where it cannot be made. */
    private static function copyOf(
        Driver $driver,
        Connection $reached,
        Reach $reach,
        string $directory,
    ): Copy|NotGiven {
        $copier = HeldConnections::copier($driver);

        try {
            return $copier->copied($reached, $reach, Unguessable::text(self::TOKEN), $directory);
        } catch (PDOException) {
            return NotGiven::value();
        }
    }
}
