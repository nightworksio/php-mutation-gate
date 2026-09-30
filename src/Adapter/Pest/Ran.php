<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** How a program ended: what it printed, and whether it succeeded or was stopped at its deadline. */
final readonly class Ran
{
    private function __construct(private string $output, private bool $succeeded, private bool $stopped)
    {
    }

    public static function finished(bool $succeeded, string $output): self
    {
        return new self($output, $succeeded, stopped: false);
    }

    public static function stopped(string $output): self
    {
        return new self($output, succeeded: false, stopped: true);
    }

    public function output(): string
    {
        return $this->output;
    }

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    public function wasStopped(): bool
    {
        return $this->stopped;
    }
}
