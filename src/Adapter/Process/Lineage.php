<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function exec;
use function getenv;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\ShellWords;
use NightWorksIO\MutationGate\Core\Runner\SparedProcesses;

use function putenv;
use function sprintf;

/**
 * The processes the gate's own process spares (ADR-0310), listed once and
 * told to it, so each process it starts is let go without a listing of its
 * own; any other process, such as a test's, lists them each time.
 *
 * @internal
 */
final readonly class Lineage
{
    /** What this process spares, listed now and told to it. */
    public static function told(): SparedProcesses
    {
        $spared = SparedProcesses::listed(self::ran(...));
        putenv(sprintf('%s=%s', SparedProcesses::VARIABLE, $spared->told()));

        return $spared;
    }

    /** What this process spares: as it was told, where it was told so itself; else as it lists them now. */
    public static function spared(): SparedProcesses
    {
        $told = getenv(SparedProcesses::VARIABLE);

        return SparedProcesses::toldOr(is_string($told) ? $told : '', self::ran(...));
    }

    /** @param list<string> $command */
    private static function ran(array $command): string
    {
        $printed = [];
        exec(ShellWords::line($command), $printed);

        return implode("\n", $printed);
    }
}
