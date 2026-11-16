<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use Closure;

use function dirname;
use function explode;
use function getmypid;
use function in_array;
use function intdiv;
use function is_string;
use function is_writable;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Processes;
use NightWorksIO\MutationGate\Core\Config\ProcessHold;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Score\Percentage;

use function preg_match;
use function putenv;
use function register_shutdown_function;
use function sprintf;

use Symfony\Component\Process\Process;

use function trim;

/**
 * Linux's cgroups as the gate holds what it starts in them
 * (`processes.contain`, ADR-0158): where the cgroup the gate runs in sits in
 * one the gate's user may write, with the memory and pids controllers handed
 * down to it, which in CI the gate hands itself (see CgroupDelegation), the
 * gate makes a cgroup of its own
 * beside it, capped at `processes.memory` of the machine's memory with no
 * swap and at `processes.pids` processes, under which each process it
 * starts runs in a cgroup of its own (see RunCgroup). The gate tells its own
 * process the cgroup once, and removes it when it exits.
 *
 * @internal
 */
final readonly class Cgroups
{
    /** The variable of the gate's own process that names its cgroup. */
    public const string VARIABLE = 'MUTATION_GATE_CGROUP';

    /** Where Linux mounts its cgroups. */
    public const string MOUNT = '/sys/fs/cgroup';

    /** Where Linux says which cgroup a process runs in. */
    public const string OWN = '/proc/self/cgroup';

    /** The controllers the gate's cgroup needs handed down, as a cgroup hands them on. */
    private const string HANDS = '+memory +pids';

    private const string GATE = '%s/mutation-gate-%d';

    /** A line of `/proc/self/cgroup` on cgroup v2: the path of the cgroup the process runs in. */
    private const string UNIFIED = '/^0::(?<path>\/.*)$/m';

    /** The line of `/proc/meminfo` that says the machine's memory. */
    private const string TOTAL = '/^MemTotal:\s+(?<kibibytes>\d+) kB$/m';

    private const string NONE = <<<'SAID'
        processes.contain is require, but %s.
        Hand the gate a cgroup it may write with the memory and pids controllers, or run it in CI where sudo needs
        no password, or set processes.contain to auto or off.
        SAID;

    private const string NOT_V2 = 'this system has no cgroup v2 at %s';

    private const string NOT_WRITABLE = 'the gate may not write %s';

    private const string NOT_HANDED = '%s hands down no memory and pids controllers';

    private const string NOT_MADE = '%s could not be made and capped, or its kernel cannot kill a cgroup whole';

    /**
     * The gate's own cgroup made as `processes.contain` says and told to this
     * process, in a cgroup handed to it first where none was and the gate may
     * hand itself one; or why it requires one this system cannot give.
     *
     * @param Closure(string): bool $delegated whether the gate's process was moved into a cgroup it owns, under a mount
     */
    public static function told(
        Processes $processes,
        string $mount,
        string $own,
        string $meminfo,
        Closure $delegated,
    ): true|CannotJudge {
        putenv(self::VARIABLE);

        if (! $processes->contain()->holds()) {
            return true;
        }

        $made = self::made($processes, $mount, CgroupFile::at($own), $meminfo);
        $made = ! is_string($made) && $delegated($mount)
            ? self::made($processes, $mount, CgroupFile::at($own), $meminfo)
            : $made;

        if (! is_string($made)) {
            return $processes->contain() === ProcessHold::Require
                ? CannotJudge::because(sprintf(self::NONE, $made->why()))
                : true;
        }

        putenv(sprintf('%s=%s', self::VARIABLE, $made));
        $gate = getmypid();
        register_shutdown_function(static function () use ($made, $gate): void {
            if (getmypid() === $gate) {
                CgroupFile::removed($made);
            }
        });

        return true;
    }

    /**
     * The gate's own cgroup on this machine, which says its memory in this
     * file, handed one first in CI, told to this process; or why not.
     */
    public static function toldHere(Processes $processes, bool $inCi, string $meminfo): true|CannotJudge
    {
        return self::told(
            $processes,
            self::MOUNT,
            self::OWN,
            $meminfo,
            static fn(string $mount): bool => $inCi && CgroupDelegation::moved(
                $mount,
                static fn(array $command): bool => new Process($command)->run() === 0,
            ),
        );
    }

    /** The gate's cgroup made, or why not. */
    private static function made(Processes $processes, string $mount, string $own, string $meminfo): string|CannotJudge
    {
        if (preg_match(self::UNIFIED, $own, $line) !== 1) {
            return CannotJudge::because(sprintf(self::NOT_V2, $mount));
        }

        $parent = $line['path'] === '/' ? $mount : dirname(sprintf('%s%s', $mount, $line['path']));
        $handed = explode(' ', trim(CgroupFile::read($parent, CgroupKnob::SubtreeControl)));

        return match (true) {
            ! is_writable($parent) || ! is_writable(CgroupKnob::Procs->of($parent))
                => CannotJudge::because(sprintf(self::NOT_WRITABLE, $parent)),
            ! in_array('memory', $handed, strict: true) || ! in_array('pids', $handed, strict: true)
                => CannotJudge::because(sprintf(self::NOT_HANDED, $parent)),
            default => self::capped(sprintf(self::GATE, $parent, getmypid()), $processes, $meminfo),
        };
    }

    /** The gate's cgroup made under its parent and capped; or why it could not be. */
    private static function capped(string $gate, Processes $processes, string $meminfo): string|CannotJudge
    {
        $total = preg_match(self::TOTAL, CgroupFile::at($meminfo), $line) === 1
            ? (int) $line['kibibytes'] * Bytes::PER_KIBIBYTE
            : 0;
        $capped = $total > 0
            && CgroupFile::wrote($gate, CgroupKnob::SubtreeControl, self::HANDS, made: true)
            && CgroupFile::wrote(
                $gate,
                CgroupKnob::MemoryMax,
                sprintf('%d', intdiv($total * $processes->memory(), Percentage::MOST)),
            )
            && CgroupFile::wrote($gate, CgroupKnob::SwapMax, '0')
            && CgroupFile::wrote($gate, CgroupKnob::PidsMax, sprintf('%d', $processes->pids()))
            && is_writable(CgroupKnob::Kill->of($gate));

        if ($capped) {
            return $gate;
        }

        CgroupFile::removed($gate);

        return CannotJudge::because(sprintf(self::NOT_MADE, $gate));
    }
}
