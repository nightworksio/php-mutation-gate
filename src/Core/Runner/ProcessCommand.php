<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A program to run as a process: its arguments, the directory it runs in,
 * what it is told on top of the environment it inherits, and how long it may
 * run before it is stopped with every process it started.
 */
final readonly class ProcessCommand
{
    private function __construct(
        private ProgramArguments $arguments,
        private string $directory,
        private Environment $environment,
        private Seconds|Unlimited $deadline,
    ) {
    }

    /** A program, then its arguments, run in a directory, told nothing, with no deadline. */
    public static function of(string $directory, string ...$arguments): self
    {
        return new self(ProgramArguments::of(...$arguments), $directory, Environment::none(), Unlimited::time());
    }

    /** This command, also told this; where it was told a variable already, it is told this instead. */
    public function with(Environment $environment): self
    {
        return new self($this->arguments, $this->directory, $this->environment->and($environment), $this->deadline);
    }

    /** This command, stopped once it has run this long. */
    public function within(Seconds|Unlimited $deadline): self
    {
        return new self($this->arguments, $this->directory, $this->environment, $deadline);
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
}
