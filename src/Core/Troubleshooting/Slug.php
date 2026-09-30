<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Troubleshooting;

/**
 * The stable name of a kind of refusal, failure or warning, which its section
 * of the troubleshooting guide is headed by (ADR-0018, decision 8). A slug is
 * public API: it never changes meaning once released.
 */
enum Slug: string
{
    case NoCoverageDriver = 'no-coverage-driver';
    case XdebugSlowsTests = 'xdebug-slows-tests';
    case OpcacheOnTheCommandLine = 'opcache-on-the-command-line';
    case TwoRunners = 'two-runners';
    case NoTree = 'no-tree';
    case ConfigRefused = 'config-refused';
    case PhpNotRead = 'php-not-read';
    case WorkspaceNotIgnored = 'workspace-not-ignored';
    case InfectionConfigToImport = 'infection-config-to-import';
    case NativeMarkersRefused = 'native-markers-refused';
    case MirroredPathRepository = 'mirrored-path-repository';
    case IgnoresExpiring = 'ignores-expiring';
    case TreeWithoutFloor = 'tree-without-floor';
    case LedgerSlowsRuns = 'ledger-slows-runs';
}
