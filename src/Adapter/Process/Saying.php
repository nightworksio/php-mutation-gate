<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function min;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Gauge;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Remaining;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * What the gate says of the processes it waits on, in a log, once a minute
 * on its own clock while any runs: how many of them have ended, how many
 * run, what the machine has left of its memory, swap and disk, how many of
 * its cores were busy since the last line, or since the first wait began,
 * the one that has run longest and for how long, and what its watch tells
 * of its progress. A run that is slow or stalled, a machine running short,
 * or cores left idle while work waits, can so be read from the log as it
 * runs.
 *
 * @internal
 */
final readonly class Saying
{
    /** How many characters of the longest-running command a line shows. */
    private const int SHOWN = 512;

    private const string LINE = 'mutation-gate: %d of %d processes ended, %d running%s; the longest for %s: %s%s';

    /** A part a line adds where it has one: what the machine has left, or the watch's progress. */
    private const string PART = '; %s';

    /**
     * @param array<int, float> $since when it first saw each process running, by its position
     */
    private function __construct(
        private ProcessLog $log,
        private ClockInterface $clock,
        private Gauge $gauge,
        private float|NotGiven $said,
        private array $since,
        private Remaining|NotGiven $read,
    ) {
    }

    /**
     * Saying in this log, on this clock, with what this gauge reads of the
     * machine, nothing until a minute after the wait it is first told of.
     */
    public static function on(ProcessLog $log, ClockInterface $clock, Gauge $gauge): self
    {
        return new self($log, $clock, $gauge, NotGiven::value(), [], NotGiven::value());
    }

    /**
     * This, having seen these processes running now, and said a line where a
     * minute has passed since it last said one, or since the wait began.
     *
     * @param list<int>                  $running  the running processes, by their positions
     * @param array<int, ProcessCommand> $commands every command, by position
     */
    public function at(array $running, array $commands, int $ended, ProcessWatch $watch): self
    {
        $now = (float) $this->clock->now()->format('U.u');
        $said = $this->said instanceof NotGiven ? $now : $this->said;
        $read = $this->lastRead();
        $since = [];

        foreach ($running as $at) {
            $since[$at] = array_key_exists($at, $this->since) ? $this->since[$at] : $now;
        }

        if ($since === [] || $now - $said < Seconds::PER_MINUTE) {
            return new self($this->log, $this->clock, $this->gauge, $said, $since, $read);
        }

        $first = min($since);
        $longest = array_keys($since, $first, strict: true)[0];
        $progress = $watch->progress();
        $reading = $this->gauge->remaining();
        $remaining = $reading->since($read);
        $this->log->say(sprintf(
            self::LINE,
            $ended,
            count($commands),
            count($since),
            $remaining === '' ? '' : sprintf(self::PART, $remaining),
            Seconds::of($now - $first)->text(),
            Fit::line(Fit::verbatim(implode(' ', [...$commands[$longest]->arguments()])), self::SHOWN),
            $progress === '' ? '' : sprintf(self::PART, $progress),
        ));

        return new self($this->log, $this->clock, $this->gauge, $now, $since, $reading);
    }

    /** What the gauge read at the last line, or now, where no line was said yet. */
    private function lastRead(): Remaining
    {
        return $this->read instanceof Remaining ? $this->read : $this->gauge->remaining();
    }
}
