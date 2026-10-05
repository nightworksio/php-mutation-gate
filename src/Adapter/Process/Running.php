<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use const INF;

use function iterator_to_array;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/** A process started in one place, until its command's deadline, on a clock read in seconds. */
final readonly class Running
{
    private function __construct(
        private Process $process,
        private int $place,
        private float $started,
        private float $until,
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

        return new self($process, $place, $now, $deadline instanceof Seconds ? $now + $deadline->seconds() : INF);
    }

    /** The position of the place it runs in, free again once it ends. */
    public function place(): int
    {
        return $this->place;
    }

    /**
     * How it ended by a time: exited, or stopped with every process it started
     * where it runs past its deadline; nothing where it runs on.
     */
    public function endedBy(float $now): Ran|NotGiven
    {
        return match (true) {
            ! $this->process->isRunning() => $this->exited($now),
            $now >= $this->until => $this->stopped($now),
            default => NotGiven::value(),
        };
    }

    private function exited(float $now): Ran
    {
        return Ran::exited($this->process->getExitCode() ?? NotGiven::value(), $this->output())
            ->took($this->since($now));
    }

    private function stopped(float $now): Ran
    {
        ProcessTree::of($this->process)->stop();

        return Ran::stopped($this->output())->took($this->since($now));
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
