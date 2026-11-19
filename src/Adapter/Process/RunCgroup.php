<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function getenv;
use function is_dir;
use function is_string;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_contains;
use function usleep;

/**
 * The cgroup of its own one process the gate starts runs in, under the
 * gate's (see Cgroups): one an out-of-memory kill takes whole, which the
 * process joins before it becomes its command, and which the gate kills
 * whole and removes once the process ends or is stopped (ADR-0158).
 *
 * @internal
 */
final readonly class RunCgroup
{
    /** What a started process runs first to join its cgroup, then becomes its command. */
    private const array JOINS = ['/bin/sh', '-c', 'echo $$ > "$0" && exec "$@"'];

    private const string RUN = '%s/started-%d';

    /** How many names a run tries, the first free one taken, before it runs uncontained. */
    private const int NAMES = 512;

    /** How many times the gate looks, at most, for a killed cgroup to empty before it leaves it. */
    private const int LOOKS = 24;

    /** How long the gate sleeps between two looks at whether a killed cgroup emptied, in microseconds. */
    private const int LOOK = 31_250;

    private function __construct(private string $path)
    {
    }

    /**
     * A cgroup for a process about to start, under the gate's, named by the
     * first number no other run holds; none where this process was told
     * none, or it cannot be made.
     */
    public static function made(): self|NotGiven
    {
        $gate = getenv(Cgroups::VARIABLE);

        for ($number = 0; is_string($gate) && $gate !== '' && $number < self::NAMES; $number++) {
            $path = sprintf(self::RUN, $gate, $number);

            if (CgroupFile::made($path)) {
                return CgroupFile::wrote($path, CgroupKnob::OomGroup, '1') ? new self($path) : self::unmade($path);
            }
        }

        return NotGiven::value();
    }

    /**
     * What a command is started under so it joins this cgroup first.
     *
     * @return list<string>
     */
    public function joining(): array
    {
        return [...self::JOINS, CgroupKnob::Procs->of($this->path)];
    }

    /** Every process in it killed, and it removed once empty. */
    public function killed(): void
    {
        if (! is_dir($this->path)) {
            return;
        }

        CgroupFile::wrote($this->path, CgroupKnob::Kill, '1');

        for ($look = 0; $look < self::LOOKS && $this->populated(); $look++) {
            usleep(self::LOOK);
        }

        CgroupFile::removed($this->path);
    }

    private static function unmade(string $path): NotGiven
    {
        CgroupFile::removed($path);

        return NotGiven::value();
    }

    private function populated(): bool
    {
        return str_contains(CgroupFile::read($this->path, CgroupKnob::Events), 'populated 1');
    }
}
