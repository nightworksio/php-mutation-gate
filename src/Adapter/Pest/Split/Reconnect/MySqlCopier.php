<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function mb_strtolower;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Statements;
use PDOException;

use function sprintf;

/**
 * MySQL's and MariaDB's sessions and copies (ADR-0147). A session holds
 * state a copy lacks where it holds a temporary table, a user variable, a
 * lock it took itself, or a statement it prepared by name; each is read
 * where the server lists it, by the Performance Schema only where it is on
 * and instruments the session, and a session the server lists none of
 * holds state. MySQL lists only InnoDB's temporary tables, of every
 * session. A setting is every session variable but those that name the
 * session, the time, the last statement's diagnostics and the last values
 * it generated.
 *
 * A database is copied into a new one of its character set and collation,
 * table by table: each base table as `SHOW CREATE TABLE` gives it, its
 * counter and foreign keys among it, then its rows but generated columns'.
 * A database holding a view, a trigger, a routine or an event is not
 * copied.
 *
 * @internal
 */
final readonly class MySqlCopier implements Copier
{
    /** Holds where the Performance Schema is on and instruments this session. */
    private const string WATCHED = <<<'SAID'
        @@performance_schema AND (SELECT INSTRUMENTED FROM
        performance_schema.threads WHERE THREAD_ID = PS_CURRENT_THREAD_ID()) = 'YES'
        SAID;

    private const string USER_VARIABLES = <<<'SAID'
        SELECT IF(%s, COUNT(*), 1) FROM performance_schema.user_variables_by_thread
        WHERE THREAD_ID = PS_CURRENT_THREAD_ID()
        SAID;

    private const string METADATA_LOCKS = <<<'SAID'
        SELECT IF(%s AND (SELECT ENABLED FROM performance_schema.setup_instruments
        WHERE NAME = 'wait/lock/metadata/sql/mdl') = 'YES', COUNT(*), 1) FROM performance_schema.metadata_locks
        WHERE OWNER_THREAD_ID = PS_CURRENT_THREAD_ID() AND LOCK_DURATION = 'EXPLICIT'
        SAID;

    private const string PREPARED = <<<'SAID'
        SELECT IF(%s, COUNT(*), 1) FROM performance_schema.prepared_statements_instances
        WHERE OWNER_THREAD_ID = PS_CURRENT_THREAD_ID() AND STATEMENT_NAME IS NOT NULL
        SAID;

    private const array SETTINGS = [
        'SELECT VARIABLE_NAME, VARIABLE_VALUE FROM performance_schema.session_variables',
        'SELECT VARIABLE_NAME, VARIABLE_VALUE FROM information_schema.SESSION_VARIABLES',
    ];

    /** The session variables that name the session, the time, the last statement and what it generated. */
    private const array OWN = [
        'pseudo_thread_id',
        'timestamp',
        'error_count',
        'warning_count',
        'last_insert_id',
        'identity',
        'last_gtid',
    ];

    private const string UNCOPIED = <<<'SQL'
        SELECT (SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE())
            + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE())
            + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE())
            + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE())
        SQL;

    private const string TABLES = <<<'SAID'
        SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        SAID;

    private const string COLUMNS = <<<'SAID'
        SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = %s AND EXTRA NOT LIKE '%%GENERATED%%' ORDER BY ORDINAL_POSITION
        SAID;

    /** Rows copy as they stand: a zero in a counter stays zero, and no key or uniqueness is checked again. */
    private const string AS_THEY_STAND
        = 'SET SESSION sql_mode = \'NO_AUTO_VALUE_ON_ZERO\', foreign_key_checks = 0, unique_checks = 0';

    private const string CURRENT = <<<'SAID'
        SELECT DATABASE(), DEFAULT_CHARACTER_SET_NAME,
        DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()
        SAID;

    private const string CREATE = 'CREATE DATABASE %s CHARACTER SET %s COLLATE %s';

    private const string SHOW = 'SHOW CREATE TABLE %s.%s';

    private const string USE = 'USE %s';

    private const string INSERT = 'INSERT INTO %s.%s (%s) SELECT %s FROM %s.%s';

    private const string MARK = '`';

    public function holdsState(Session $session): bool
    {
        return $session->countsAny([
            [
                'SELECT COUNT(*) FROM information_schema.TEMPORARY_TABLES',
                'SELECT COUNT(*) FROM information_schema.INNODB_TEMP_TABLE_INFO',
            ],
            [sprintf(self::USER_VARIABLES, self::WATCHED), 'SELECT COUNT(*) FROM information_schema.USER_VARIABLES'],
            [
                sprintf(self::METADATA_LOCKS, self::WATCHED),
                'SELECT COUNT(*) FROM information_schema.METADATA_LOCK_INFO WHERE THREAD_ID = CONNECTION_ID()',
            ],
            [sprintf(self::PREPARED, self::WATCHED)],
        ]);
    }

    /** @return list<list<string>>|null */
    public function settings(Session $session): ?array
    {
        $rows = $session->first(self::SETTINGS);

        return $rows === null ? null : array_values(array_filter(
            $rows,
            static fn(array $row): bool => ! in_array(mb_strtolower($row[0] ?? ''), self::OWN, strict: true),
        ));
    }

    /** @param array<mixed> $config */
    public function copied(Connection $original, array $config, string $token, string $directory): ?Copy
    {
        $database = $original->opened();
        [$current, $characters, $collation] = ($database->rows(self::CURRENT)[0] ?? []) + ['', '', ''];

        if ($current === '' || ($database->rows(self::UNCOPIED)[0][0] ?? '') !== '0') {
            return null;
        }

        $from = self::quoted($current);
        $name = CopyName::of($current, $token);
        $tables = array_map(static fn(array $row): string => $row[0], $database->rows(self::TABLES));
        $database->run(sprintf(self::CREATE, self::quoted($name), self::quoted($characters), self::quoted($collation)));
        $copy = new Copy($name, $original, $this);

        try {
            $this->filled($database, $tables, $from, self::quoted($name));
        } catch (PDOException $failed) {
            $copy->dropped();

            throw $failed;
        }

        return $copy;
    }

    public function drop(Connection $admin, string $copy): void
    {
        $admin->opened()->run(sprintf(self::DROP, self::quoted($copy)));
    }

    /**
     * @param list<string> $tables
     *
     * @throws PDOException
     */
    private function filled(Statements $database, array $tables, string $from, string $to): void
    {
        $database->run(self::AS_THEY_STAND);
        $creates = [];
        $inserts = [];

        foreach ($tables as $table) {
            $quoted = self::quoted($table);
            $creates[] = $database->rows(sprintf(self::SHOW, $from, $quoted))[0][1] ?? '';
            $listed = implode(', ', array_map(
                static fn(array $row): string => self::quoted($row[0]),
                $database->rows(sprintf(self::COLUMNS, $database->quoted($table))),
            ));
            $inserts = $listed === ''
                ? $inserts
                : [...$inserts, sprintf(self::INSERT, $to, $quoted, $listed, $listed, $from, $quoted)];
        }

        $database->run(sprintf(self::USE, $to));

        foreach ([...$creates, ...$inserts] as $statement) {
            $database->run($statement);
        }
    }

    private static function quoted(string $name): string
    {
        return CopyName::quoted($name, self::MARK);
    }
}
