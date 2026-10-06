<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_map;
use function escapeshellarg;
use function exec;
use function hrtime;
use function implode;
use function is_int;

use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function usleep;

/**
 * A worker waiting for its child: until it ends, or until its limit, when the
 * child and every process under it are stopped, looking about a hundred
 * times a second, on `hrtime`'s clock.
 */
final readonly class Waiting
{
    private function __construct(private int $child, private float $limit, private int|float $started)
    {
    }

    /** A wait for the child with this process id, allowed this many seconds from when it started. */
    public static function for(int $child, float $limit, int|float $started): self
    {
        return new self($child, $limit, $started);
    }

    /** How the child ended, or that it was stopped at its limit, with what it printed from where it started. */
    public function ended(Printed $from, Workplace $workplace): End
    {
        $status = 0;
        $waited = pcntl_waitpid($this->child, $status, WNOHANG);

        while ($waited === 0 && $this->seconds() < $this->limit) {
            usleep(Polling::interval()->microseconds());
            $waited = pcntl_waitpid($this->child, $status, WNOHANG);
        }

        if ($waited === 0 || ! is_int($status)) {
            $this->stop();

            return End::stopped($this->seconds(), $from->untilNow($workplace));
        }

        return pcntl_wifsignaled($status)
            ? End::signalled((int) pcntl_wtermsig($status), $this->seconds(), $from->untilNow($workplace))
            : End::exited((int) pcntl_wexitstatus($status), $this->seconds(), $from->untilNow($workplace));
    }

    /** The child stopped with every process under it, deepest first, and reaped. */
    private function stop(): void
    {
        $listing = [];
        exec($this->line(ProcessTable::LISTING), $listing);
        $under = ProcessTable::parse(implode("\n", $listing))->descendantsOf($this->child);
        exec($this->line(ProcessTable::killing(...[...$under, $this->child])));
        $status = 0;
        pcntl_waitpid($this->child, $status);
    }

    /** @param list<string> $command */
    private function line(array $command): string
    {
        return implode(' ', array_map(escapeshellarg(...), $command));
    }

    private function seconds(): float
    {
        return (hrtime(as_number: true) - $this->started) / Seconds::NANOSECONDS;
    }
}
