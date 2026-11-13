<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function file_exists;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use PDOException;

use function sprintf;
use function unlink;

/**
 * SQLite's sessions and copies (ADR-0147). A session holds state a copy
 * lacks where it holds a temporary table or another database attached; a
 * setting is each pragma a connection holds for itself that changes what a
 * statement does. A file is copied by `VACUUM INTO`, which writes its
 * committed state, a write-ahead log's among it, into the stream's own
 * directory; the copy is dropped with its journal and log.
 *
 * @internal
 */
final readonly class SqliteCopier implements Copier
{
    private const array STATE = [
        ['SELECT COUNT(*) FROM sqlite_temp_master'],
        ["SELECT COUNT(*) FROM pragma_database_list WHERE name NOT IN ('main', 'temp')"],
    ];

    private const array PRAGMAS = [
        'foreign_keys',
        'defer_foreign_keys',
        'recursive_triggers',
        'ignore_check_constraints',
        'legacy_alter_table',
        'reverse_unordered_selects',
        'automatic_index',
        'query_only',
        'synchronous',
        'temp_store',
        'cache_size',
    ];

    private const string PRAGMA = "SELECT '%1\$s', * FROM pragma_%1\$s";

    private const string PATH = '%s/copy-%s.sqlite';

    private const string INTO = 'VACUUM INTO %s';

    /** The files SQLite keeps beside a database. */
    private const array BESIDE = ['', '-journal', '-wal', '-shm'];

    public function holdsState(Session $session): bool
    {
        return $session->countsAny(self::STATE);
    }

    /** @return list<list<string>>|null */
    public function settings(Session $session): ?array
    {
        $settings = [];

        foreach (self::PRAGMAS as $pragma) {
            $rows = $session->first([sprintf(self::PRAGMA, $pragma)]);

            if ($rows === null) {
                return null;
            }

            $settings = [...$settings, ...$rows];
        }

        return $settings;
    }

    /** @param array<mixed> $config */
    public function copied(Connection $original, array $config, string $token, string $directory): Copy
    {
        $path = sprintf(self::PATH, $directory, $token);
        $copy = new Copy($path, $original, $this);
        $database = $original->opened();

        try {
            $database->run(sprintf(self::INTO, $database->quoted($path)));
        } catch (PDOException $failed) {
            $copy->dropped();

            throw $failed;
        }

        return $copy;
    }

    public function drop(Connection $admin, string $copy): void
    {
        foreach (self::BESIDE as $suffix) {
            $beside = sprintf('%s%s', $copy, $suffix);

            if (file_exists($beside)) {
                unlink($beside);
            }
        }
    }
}
