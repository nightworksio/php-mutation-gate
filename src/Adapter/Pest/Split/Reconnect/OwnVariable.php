<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect;

/**
 * A MySQL or MariaDB session variable that names the session, the time, the
 * last statement's diagnostics or the last value it generated, which no two
 * sessions hold alike (ADR-0147).
 *
 * @internal
 */
enum OwnVariable: string
{
    case PseudoThreadId = 'pseudo_thread_id';
    case Timestamp = 'timestamp';
    case ErrorCount = 'error_count';
    case WarningCount = 'warning_count';
    case LastInsertId = 'last_insert_id';
    case Identity = 'identity';
    case LastGtid = 'last_gtid';
}
