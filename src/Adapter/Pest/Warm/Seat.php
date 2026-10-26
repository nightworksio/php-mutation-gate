<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function sprintf;

/**
 * The files of one place among a Pest run's warm workers (ADR-0023), in the
 * directory the run's workers share: the worker's process id, the request a
 * mutant's run leaves for it, where each run it took started and how it
 * ended, what the worker and its children printed, its test databases'
 * report, and why it forked a mutant's run no child.
 */
final readonly class Seat
{
    /** The seconds a worker is given to start, and to end once told the run is done. */
    public const float WAIT = 10.0;
    private const string STOP = 'stop';

    private function __construct(private string $directory, private int $place)
    {
    }

    public static function at(string $directory, int $place): self
    {
        return new self($directory, $place);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function place(): int
    {
        return $this->place;
    }

    /** Where the gate says the run's workers are done. */
    public function stop(): string
    {
        return sprintf('%s/%s', $this->directory, self::STOP);
    }

    /** The worker's process id, as its parent started it. */
    public function pid(): string
    {
        return $this->in('pid');
    }

    /** The next run a mutant's own run asks the worker for. */
    public function request(): string
    {
        return $this->in('request');
    }

    /** Where the run with this id started on the worker's output files. */
    public function started(string $id): string
    {
        return $this->in(sprintf('%s.started', $id));
    }

    /** The process id of the child the worker forked for the run with this id. */
    public function child(string $id): string
    {
        return $this->in(sprintf('%s.child', $id));
    }

    /** How the run with this id ended, once it has. */
    public function ended(string $id): string
    {
        return $this->in(sprintf('%s.ended', $id));
    }

    /** What the worker and each child it forked printed on standard output. */
    public function out(): string
    {
        return $this->in('out');
    }

    /** What the worker and each child it forked printed on standard error. */
    public function err(): string
    {
        return $this->in('err');
    }

    /** What the child that ran last reported of its test databases. */
    public function report(): string
    {
        return $this->in('database');
    }

    /** Each reason the worker forked a mutant's run no child, one a line. */
    public function warned(): string
    {
        return $this->in('warned');
    }

    private function in(string $name): string
    {
        return sprintf('%s/%d.%s', $this->directory, $this->place, $name);
    }
}
