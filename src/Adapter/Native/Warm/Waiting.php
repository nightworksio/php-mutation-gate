<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

use function exec;
use function hrtime;
use function implode;
use function is_int;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MaxRss;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Runner\ShellWords;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function usleep;

/**
 * A worker waiting for its child: until it ends, until its limit, until
 * no test of it has started or ended for its silence limit where it has one,
 * or until a test of it runs past its own limit where it is watched
 * (ADR-0090), when the child and every process under it are stopped, looking
 * about a hundred times a second, on `hrtime`'s clock.
 *
 * @internal
 */
final readonly class Waiting
{
    private function __construct(
        private int $child,
        private Seconds $limit,
        private int|float $started,
        private SilenceLimit|NotGiven $silence,
    ) {
    }

    /** A wait for the child with this process id, allowed this many seconds from when it started, and this silence. */
    public static function for(int $child, float $limit, int|float $started, SilenceLimit|NotGiven $silence): self
    {
        return new self($child, Seconds::of($limit), $started, $silence);
    }

    /**
     * How the child ended, or that it was stopped at its limit or its silence
     * limit, with what it printed from where it started, and the most
     * memory a child that ended held.
     */
    public function ended(Printed $from, Workplace $workplace): End
    {
        $progress = $this->firstProgress();
        $tests = TestEventsTail::of($this->silence);
        [$waited, $status, $held] = $this->looked();

        while ($waited === 0 && $this->limit->outlasts(Seconds::of($this->seconds()))) {
            $progress = $this->read($progress);
            $early = $this->stoppedEarly($progress, $tests->overrun($this->seconds()), $from, $workplace);

            if ($early instanceof End) {
                return $early;
            }

            usleep(Polling::interval()->microseconds());
            [$waited, $status, $held] = $this->looked();
        }

        if ($waited === 0 || ! is_int($status)) {
            $this->stop();

            return End::stopped($this->seconds(), $from->untilNow($workplace));
        }

        return $this->endedOf($status, $from, $workplace)->peaking($held);
    }

    /**
     * One look at the child, which reaps it where it has ended: what
     * `waitpid` answered, the child's status where it gave one, and the
     * `ru_maxrss` it reported of the child, 0 where it reported none.
     *
     * @return array{int, int|NotGiven, int}
     */
    private function looked(): array
    {
        $waited = pcntl_waitpid($this->child, $status, WNOHANG, $usage);
        return [$waited, is_int($status) ? $status : NotGiven::value(), MaxRss::held($usage)];
    }

    /** How a child that ended of itself ended: by a signal, or with an exit code. */
    private function endedOf(int $status, Printed $from, Workplace $workplace): End
    {
        return pcntl_wifsignaled($status)
            ? End::signalled((int) pcntl_wtermsig($status), $this->seconds(), $from->untilNow($workplace))
            : End::exited((int) pcntl_wexitstatus($status), $this->seconds(), $from->untilNow($workplace));
    }

    /**
     * The child stopped, where it has made no progress for its silence limit
     * or its test has run past its own; nothing where it runs on.
     */
    private function stoppedEarly(
        Progress|NotGiven $progress,
        Overrun|NotGiven $overrun,
        Printed $from,
        Workplace $workplace,
    ): End|NotGiven {
        $silent = $progress instanceof Progress && $progress->hasStalled($this->seconds());

        if (! $silent && ! $overrun instanceof Overrun) {
            return NotGiven::value();
        }

        $this->stop();

        return $overrun instanceof Overrun && ! $silent
            ? End::overran($overrun, $this->seconds(), $from->untilNow($workplace))
            : End::silenced($this->seconds(), $from->untilNow($workplace));
    }

    /** The progress the child starts from; none where it has no silence limit. */
    private function firstProgress(): Progress|NotGiven
    {
        return Progress::of($this->silence);
    }

    /** The progress as its file stands now; none where the child has no silence limit. */
    private function read(Progress|NotGiven $progress): Progress|NotGiven
    {
        if (! $this->silence instanceof SilenceLimit || ! $progress instanceof Progress) {
            return $progress;
        }

        return $progress->read(FileSize::now($this->silence->progress()), $this->seconds());
    }

    /** The child stopped with every process under it, deepest first, and reaped. */
    private function stop(): void
    {
        ProcessTable::stop($this->child, static function (array $command): string {
            $printed = [];
            exec(ShellWords::line($command), $printed);

            return implode("\n", $printed);
        });
        pcntl_waitpid($this->child, $status);
    }

    private function seconds(): float
    {
        return (hrtime(as_number: true) - $this->started) / Seconds::NANOSECONDS;
    }
}
