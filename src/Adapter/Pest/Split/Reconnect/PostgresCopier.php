<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\LaravelConfig;

use function sprintf;

/**
 * PostgreSQL's sessions and copies (ADR-0147). A session holds state a copy
 * lacks where it holds a temporary table, an advisory lock, a channel it
 * listens on, a statement it prepared by SQL or a cursor; a setting is each
 * of `pg_settings`. A database is copied as the template of a new one,
 * through the server's maintenance database, which no session of the
 * original may be connected to then.
 *
 * @internal
 */
final readonly class PostgresCopier implements Copier
{
    private const array STATE = [
        ['SELECT COUNT(*) FROM pg_class WHERE relnamespace = pg_my_temp_schema()'],
        ["SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"],
        ['SELECT COUNT(*) FROM pg_listening_channels()'],
        ['SELECT COUNT(*) FROM pg_prepared_statements WHERE from_sql'],
        ['SELECT COUNT(*) FROM pg_cursors'],
    ];

    private const array SETTINGS = ['SELECT name, setting FROM pg_settings'];

    /** The database every PostgreSQL server holds, which a copy is made and dropped from. */
    private const string MAINTENANCE = 'postgres';

    private const string CREATE = 'CREATE DATABASE %s TEMPLATE %s';

    private const string MARK = '"';

    public function holdsState(Session $session): bool
    {
        return $session->countsAny(self::STATE);
    }

    /** @return list<list<string>>|null */
    public function settings(Session $session): ?array
    {
        return $session->first(self::SETTINGS);
    }

    /** @param array<mixed> $config */
    public function copied(Connection $original, array $config, string $token, string $directory): ?Copy
    {
        $admin = LaravelConfig::connection([...$config, LaravelConfig::DATABASE => self::MAINTENANCE]);
        $database = LaravelConfig::database($config);

        if (! $admin instanceof Connection || $database === '') {
            return null;
        }

        $name = CopyName::of($database, $token);
        $admin->opened()->run(sprintf(self::CREATE, $this->quoted($name), $this->quoted($database)));

        return new Copy($name, $admin, $this);
    }

    public function drop(Connection $admin, string $copy): void
    {
        $admin->opened()->run(sprintf(self::DROP, $this->quoted($copy)));
    }

    private function quoted(string $name): string
    {
        return CopyName::quoted($name, self::MARK);
    }
}
