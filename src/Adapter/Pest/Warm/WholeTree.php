<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function exec;
use function implode;

use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\ShellWords;

/**
 * A process of a Pest run's warm worker, a child, with every process under
 * it (ADR-0023), stopped through PHP's own `exec()`, since the worker and
 * its clients load the gate's classes alone (see ProcessTable).
 *
 * @internal
 */
final readonly class WholeTree
{
    /** The process with this id stopped, with every process under it, deepest first. */
    public static function stopped(int $pid): void
    {
        ProcessTable::stop($pid, static function (array $command): string {
            $printed = [];
            exec(ShellWords::line($command), $printed);

            return implode("\n", $printed);
        });
    }
}
