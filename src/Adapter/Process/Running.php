<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function array_values;
use function clearstatcache;

use Closure;

use function fclose;
use function feof;
use function filesize;
use function getenv;

use const INF;

use function is_dir;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Runner\Environment;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function strval;
use function usleep;

/**
 * A process started in one place, until its command's deadline, or until it
 * has made no progress for its silence limit where it has one, on a clock
 * read in seconds. What it prints is read as it prints it, so a wait on
 * running processes ends as soon as one prints or ends.
 */
final class Running
{
    /** Where the process reads from, and the pipes it writes its output and its errors to. */
    private const array DESCRIPTORS = [
        0 => ['file', '/dev/null', 'r'],
        self::OUTPUT => ['pipe', 'w'],
        self::ERRORS => ['pipe', 'w'],
    ];

    private const int OUTPUT = 1;

    private const int ERRORS = 2;

    /** The signal that ends a process at once, sent to one its tree's stop missed. */
    private const int KILL = 9;

    /** Why a command is not started: its directory is not there. */

    /** Why a command is not started: the system made no process for it. */
    private const string NOT_STARTED = 'No process could be started for %s.';

    /**
     * @param resource                   $process
     * @param array<int, resource>       $pipes   each pipe still open, by descriptor
     * @param array<int, string>         $printed what came through each pipe, by descriptor
     */
    private function __construct(
        private readonly mixed $process,
        private readonly int $place,
        private readonly float $started,
        private readonly float $until,
        private readonly SilenceLimit|NotGiven $silence,
        private Progress|NotGiven $progress,
        private array $pipes,
        private array $printed = [self::OUTPUT => '', self::ERRORS => ''],
    ) {
    }

    /**
     * A command started in a place, by its position among the places, at a
     * time; or how it ended, where it could not be started.
     */
    public static function start(ProcessCommand $command, int $place, WorkerSlot $slot, float $now): self|Ran
    {
        $arguments = [...$command->arguments()];

        if (! is_dir($command->directory())) {
            return self::unstarted(sprintf(ChildProcess::NO_DIRECTORY, $command->directory()));
        }

        $pipes = [];
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $process = proc_open(
                $arguments,
                self::DESCRIPTORS,
                $pipes,
                $command->directory(),
                self::environment($command->in($slot)->environment()),
            );
        } finally {
            restore_error_handler();
        }

        if (! is_resource($process)) {
            return self::unstarted(sprintf(self::NOT_STARTED, $arguments[0]));
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, enable: false);
        }

        $deadline = $command->deadline();
        $silence = $command->silence();

        return new self(
            $process,
            $place,
            $now,
            $deadline instanceof Seconds ? $now + $deadline->seconds() : INF,
            $silence,
            $silence instanceof SilenceLimit ? Progress::under($silence->limit()) : NotGiven::value(),
            $pipes,
        );
    }

    /**
     * Waits until one of these processes prints or ends, or this long has
     * passed; a moment only where one has closed its pipes and runs on, as a
     * process does on its way out.
     */
    public static function awaitAny(Seconds $atMost, self ...$running): void
    {
        $read = [];
        $closing = false;

        foreach ($running as $process) {
            $read = [...$read, ...array_values($process->pipes)];
            $closing = $closing || $process->pipes === [];
        }

        $wait = $closing && Polling::moment()->seconds() < $atMost->seconds() ? Polling::moment() : $atMost;

        if ($read === []) {
            usleep($wait->microseconds());

            return;
        }

        $none = null;
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            stream_select($read, $none, $none, 0, $wait->microseconds());
        } finally {
            restore_error_handler();
        }
    }

    /** The position of the place it runs in, free again once it ends. */
    public function place(): int
    {
        return $this->place;
    }

    /**
     * How it ended by a time: exited, or stopped with every process it started
     * where it runs past its deadline or has stalled; nothing where it runs on.
     */
    public function endedBy(float $now): Ran|NotGiven
    {
        $this->read();
        $status = proc_get_status($this->process);

        return match (true) {
            ! $status['running'] => $this->exited(
                signalled: $status['signaled'],
                signal: $status['termsig'],
                code: $status['exitcode'],
                now: $now,
            ),
            $now >= $this->until => $this->stopped(Ran::stopped(...), $now, $status['pid']),
            $this->hasStalled($now) => $this->stopped(Ran::silenced(...), $now, $status['pid']),
            default => NotGiven::value(),
        };
    }

    /**
     * The environment it runs in: this process's own, with what `$_ENV`
     * holds over it, as Symfony's Process hands on, then what its command
     * tells and without what it unsets.
     *
     * @return array<string, string>
     */
    private static function environment(Environment $told): array
    {
        $variables = [];

        foreach (getenv() as $name => $value) {
            $variables[strval($name)] = $value;
        }

        foreach ($_ENV as $name => $value) {
            if (is_string($value)) {
                $variables[strval($name)] = $value;
            }
        }

        foreach ($told as $name => $value) {
            unset($variables[$name]);

            if (is_string($value)) {
                $variables[$name] = $value;
            }
        }

        return $variables;
    }

    private static function unstarted(string $why): Ran
    {
        return Ran::finished(succeeded: false, output: $why)->took(Seconds::of(0.0));
    }

    /** Whether, at this time, it has made no progress for its silence limit; never where it has none. */
    private function hasStalled(float $now): bool
    {
        if (! $this->silence instanceof SilenceLimit || ! $this->progress instanceof Progress) {
            return false;
        }

        $file = $this->silence->progress();
        clearstatcache(clear_realpath_cache: true, filename: $file);
        $size = is_file($file) ? filesize($file) : 0;
        $this->progress = $this->progress->read(is_int($size) ? $size : 0, $now);

        return $this->progress->hasStalled($now);
    }

    /** Whatever each pipe holds now, kept; a pipe at its end is closed. */
    private function read(): void
    {
        foreach ($this->pipes as $descriptor => $pipe) {
            $read = stream_get_contents($pipe);
            $this->printed[$descriptor] .= is_string($read) ? $read : '';

            if (feof($pipe)) {
                fclose($pipe);
                unset($this->pipes[$descriptor]);
            }
        }
    }

    /** How it ended where it exited: by a signal, or with a code. */
    private function exited(bool $signalled, int $signal, int $code, float $now): Ran
    {
        $this->close();
        $ran = $signalled
            ? Ran::signalled($signal, $this->output(), $this->printed[self::OUTPUT])
            : Ran::exited($code, $this->output(), $this->printed[self::OUTPUT]);

        return $ran->took($this->since($now));
    }

    /** @param Closure(string, string): Ran $ended how it ended, by what it printed on both streams and on its output */
    private function stopped(Closure $ended, float $now, int $pid): Ran
    {
        ProcessTree::of($pid)->stop();
        proc_terminate($this->process, self::KILL);
        $this->close();

        return $ended($this->output(), $this->printed[self::OUTPUT])->took($this->since($now));
    }

    /** What is left in its pipes kept, the pipes closed, and the process reaped. */
    private function close(): void
    {
        $this->read();

        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }

        $this->pipes = [];
        proc_close($this->process);
    }

    private function since(float $now): Seconds
    {
        return Seconds::of($now - $this->started);
    }

    private function output(): string
    {
        return sprintf('%s%s', $this->printed[self::OUTPUT], $this->printed[self::ERRORS]);
    }
}
