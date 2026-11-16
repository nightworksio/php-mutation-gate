<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use Closure;

use function getmypid;
use function posix_getegid;
use function posix_geteuid;
use function sprintf;

/**
 * A cgroup handed to the gate's user where none is, as the gate does in CI
 * alone, where `sudo` needs no password (ADR-0158): `mutation-gate` under
 * the mount, owned by the gate's user, with the memory and pids controllers
 * handed down, and a cgroup in it the gate's own process moves into, beside
 * which the gate then makes its own.
 *
 * @internal
 */
final readonly class CgroupDelegation
{
    private const string HELD = '%s/mutation-gate';

    private const string LEAF = '%s/gate-%d';

    /** What `sudo` runs to write a value, its last word, into a file, its first. */
    private const array WRITES = ['sudo', '-n', 'sh', '-c', 'echo "$1" > "$0"'];

    /**
     * Whether the gate's own process now runs in a cgroup its user owns.
     *
     * @param Closure(list<string>): bool $succeeds whether a command exits with 0
     */
    public static function moved(string $mount, Closure $succeeds): bool
    {
        $held = sprintf(self::HELD, $mount);
        $leaf = sprintf(self::LEAF, $held, getmypid());

        return $succeeds(['sudo', '-n', 'mkdir', '-p', $leaf])
            && $succeeds(['sudo', '-n', 'chown', '-R', sprintf('%d:%d', posix_geteuid(), posix_getegid()), $held])
            && $succeeds([...self::WRITES, CgroupKnob::SubtreeControl->of($held), '+memory +pids'])
            && $succeeds([...self::WRITES, CgroupKnob::Procs->of($leaf), sprintf('%d', getmypid())]);
    }
}
