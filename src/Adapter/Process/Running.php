<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function clearstatcache;

use Closure;

use function filesize;

use const INF;

use function is_file;
use function is_int;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * A process started in one place, until its command's deadline, or until it
 * has made no progress for its silence limit where it has one, on a clock
 * read in seconds.
 */
final class Running
{
    private function __construct(
        private readonly Process $process,
        private readonly int $place,
        private readonly float $started,
        private readonly float $until,
        private readonly SilenceLimit|NotGiven $silence,
        private Progress|NotGiven $progress,
    ) {
    }

    /**
     * A command started in a place, by its position among the places, at a
     * time; or how it ended, where it could not be started.
     */
    public static function start(ProcessCommand $command, int $place, WorkerSlot $slot, float $now): self|Ran
    {
        $process = new Process(
            [...$command->arguments()],
            $command->directory(),
            iterator_to_array($command->in($slot)->environment(), preserve_keys: true),
            timeout: null,
        );

        try {
            $process->start();
        } catch (ExceptionInterface $failure) {
            return Ran::finished(succeeded: false, output: $failure->getMessage())->took(Seconds::of(0.0));
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
        );
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
        return match (true) {
            ! $this->process->isRunning() => $this->exited($now),
            $now >= $this->until => $this->stopped(Ran::stopped(...), $now),
            $this->hasStalled($now) => $this->stopped(Ran::silenced(...), $now),
            default => NotGiven::value(),
        };
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

    private function exited(float $now): Ran
    {
        return Ran::exited($this->process->getExitCode() ?? NotGiven::value(), $this->output())
            ->took($this->since($now));
    }

    /** @param Closure(string): Ran $ended how it ended, by what it printed */
    private function stopped(Closure $ended, float $now): Ran
    {
        ProcessTree::of($this->process)->stop();

        return $ended($this->output())->took($this->since($now));
    }

    private function since(float $now): Seconds
    {
        return Seconds::of($now - $this->started);
    }

    private function output(): string
    {
        return sprintf('%s%s', $this->process->getOutput(), $this->process->getErrorOutput());
    }
}
