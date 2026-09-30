<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

/** How a program ended: what it printed, and whether it succeeded, failed or was stopped at its deadline. */
final readonly class Ran
{
    private function __construct(private string $output, private Ending $ending)
    {
    }

    public static function finished(bool $succeeded, string $output): self
    {
        return new self($output, $succeeded ? Ending::Succeeded : Ending::Failed);
    }

    public static function stopped(string $output): self
    {
        return new self($output, Ending::Stopped);
    }

    public function output(): string
    {
        return $this->output;
    }

    public function succeeded(): bool
    {
        return $this->ending === Ending::Succeeded;
    }

    public function wasStopped(): bool
    {
        return $this->ending === Ending::Stopped;
    }
}
