<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A program to run as a process: its arguments, the directory it runs in,
 * what it is told on top of the environment it inherits, how long it may
 * run before it is stopped with every process it started, how long it
 * may go without progress, where it has a silence limit, and how long it
 * may run past the time its pool starts no more commands before it is
 * halted, where it is held to that (ADR-0197).
 */
final readonly class ProcessCommand
{
    private function __construct(
        private ProgramArguments $arguments,
        private string $directory,
        private Environment $environment,
        private Seconds|Unlimited $deadline,
        private SilenceLimit|NotGiven $silence,
        private Seconds|NotGiven $grace = new NotGiven(),
    ) {
    }

    /** A program, then its arguments, run in a directory, told nothing, with no deadline. */
    public static function of(string $directory, string ...$arguments): self
    {
        return new self(
            ProgramArguments::of(...$arguments),
            $directory,
            Environment::none(),
            Unlimited::time(),
            NotGiven::value(),
        );
    }

    /** This script, told these arguments as `$1` and on, run by the shell in a directory. */
    public static function inShell(string $directory, string $script, string ...$arguments): self
    {
        return new self(
            ProgramArguments::of('sh', '-c', $script, 'sh', ...$arguments),
            $directory,
            Environment::none(),
            Unlimited::time(),
            NotGiven::value(),
        );
    }

    /** This command, also told this; where it was told a variable already, it is told this instead. */
    public function with(Environment $environment): self
    {
        return new self(
            $this->arguments,
            $this->directory,
            $this->environment->and($environment),
            $this->deadline,
            $this->silence,
            $this->grace,
        );
    }

    /** This command, stopped once it has run this long. */
    public function within(Seconds|Unlimited $deadline): self
    {
        return new self(
            $this->arguments,
            $this->directory,
            $this->environment,
            $deadline,
            $this->silence,
            $this->grace,
        );
    }

    /** This command, also stopped once it has made no progress for its silence limit. */
    public function silencedAfter(SilenceLimit $silence): self
    {
        return new self(
            $this->arguments,
            $this->directory,
            $this->environment,
            $this->deadline,
            $silence,
            $this->grace,
        );
    }

    /**
     * This command, also halted once it has run this long past the time its
     * pool starts no more commands, its run then telling nothing (ADR-0197).
     */
    public function haltedPast(Seconds $grace): self
    {
        return new self(
            $this->arguments,
            $this->directory,
            $this->environment,
            $this->deadline,
            $this->silence,
            $grace,
        );
    }

    /** This command as it runs in one of several places side by side: told what that place tells it. */
    public function in(WorkerSlot $slot): self
    {
        return $this->with($slot->variables());
    }

    public function arguments(): ProgramArguments
    {
        return $this->arguments;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function deadline(): Seconds|Unlimited
    {
        return $this->deadline;
    }

    /** How long it may go without progress; none where only its deadline stops it. */
    public function silence(): SilenceLimit|NotGiven
    {
        return $this->silence;
    }

    /** How long it may run past the time its pool starts no more commands; none where nothing halts it then. */
    public function grace(): Seconds|NotGiven
    {
        return $this->grace;
    }
}
