<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function min;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessLog;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * What the gate says of the processes it waits on, once a minute while any
 * runs: how many of them have ended, how many run, the one that has run
 * longest and for how long, and what its watch tells of its progress. A run
 * that is slow or stalled can so be read from the log as it runs.
 */
final readonly class Saying
{
    /** How many characters of the longest-running command a line shows. */
    private const int SHOWN = 140;

    private const string LINE = 'mutation-gate: %d of %d processes ended, %d running; the longest for %s: %s%s';

    private const string PROGRESS = '; %s';

    /**
     * @param array<int, float> $since when the gate first saw each process running, by its position
     */
    private function __construct(private ProcessLog $log, private float $said, private array $since)
    {
    }

    /** Saying nothing until a minute from now. */
    public static function from(ProcessLog $log, float $now): self
    {
        return new self($log, $now, []);
    }

    /**
     * This, having seen these processes running now, and said a line where a
     * minute has passed since it last said one.
     *
     * @param list<int>                  $running  the running processes, by their positions
     * @param array<int, ProcessCommand> $commands every command, by position
     */
    public function at(float $now, array $running, array $commands, int $ended, ProcessWatch $watch): self
    {
        $since = [];

        foreach ($running as $at) {
            $since[$at] = array_key_exists($at, $this->since) ? $this->since[$at] : $now;
        }

        if ($since === [] || $now - $this->said < Seconds::PER_MINUTE) {
            return new self($this->log, $this->said, $since);
        }

        $first = min($since);
        $longest = array_keys($since, $first, strict: true)[0];
        $progress = $watch->progress();
        $this->log->say(sprintf(
            self::LINE,
            $ended,
            count($commands),
            count($since),
            Seconds::of($now - $first)->text(),
            Fit::line(Fit::verbatim(implode(' ', [...$commands[$longest]->arguments()])), self::SHOWN),
            $progress === '' ? '' : sprintf(self::PROGRESS, $progress),
        ));

        return new self($this->log, $now, $since);
    }
}
