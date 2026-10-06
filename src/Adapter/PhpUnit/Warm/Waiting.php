<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function array_map;
use function clearstatcache;
use function escapeshellarg;
use function exec;
use function filesize;
use function hrtime;
use function implode;
use function is_file;
use function is_int;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function usleep;

/**
 * A worker waiting for its child: until it ends, until its limit, or until
 * no test of it has started or ended for its silence limit where it has one,
 * when the child and every process under it are stopped, looking about a
 * hundred times a second, on `hrtime`'s clock.
 */
final readonly class Waiting
{
    private function __construct(
        private int $child,
        private float $limit,
        private int|float $started,
        private SilenceLimit|NotGiven $silence,
    ) {
    }

    /** A wait for the child with this process id, allowed this many seconds from when it started, and this silence. */
    public static function for(int $child, float $limit, int|float $started, SilenceLimit|NotGiven $silence): self
    {
        return new self($child, $limit, $started, $silence);
    }

    /**
     * How the child ended, or that it was stopped at its limit or its silence
     * limit, with what it printed from where it started.
     */
    public function ended(Printed $from, Workplace $workplace): End
    {
        $status = 0;
        $progress = $this->silence instanceof SilenceLimit ? Progress::under($this->silence) : NotGiven::value();
        $waited = pcntl_waitpid($this->child, $status, WNOHANG);

        while ($waited === 0 && $this->seconds() < $this->limit) {
            $progress = $this->read($progress);

            if ($progress instanceof Progress && $progress->hasStalled($this->seconds())) {
                $this->stop();

                return End::silenced($this->seconds(), $from->untilNow($workplace));
            }

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

    /** The progress as its file stands now; none where the child has no silence limit. */
    private function read(Progress|NotGiven $progress): Progress|NotGiven
    {
        if (! $progress instanceof Progress) {
            return $progress;
        }

        $file = $progress->file();
        clearstatcache(clear_realpath_cache: true, filename: $file);
        $size = is_file($file) ? filesize($file) : 0;

        return $progress->read(is_int($size) ? $size : 0, $this->seconds());
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
