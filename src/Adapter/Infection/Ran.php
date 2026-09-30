<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How a program ended: what it printed, whether it succeeded or was stopped
 * at its deadline, and how long it ran.
 */
final readonly class Ran
{
    private function __construct(
        private string $output,
        private bool $succeeded,
        private bool $stopped,
        private Seconds $took,
    ) {
    }

    public static function finished(bool $succeeded, string $output): self
    {
        return new self($output, $succeeded, stopped: false, took: Seconds::of(0.0));
    }

    public static function stopped(string $output): self
    {
        return new self($output, succeeded: false, stopped: true, took: Seconds::of(0.0));
    }

    /** This ending, after the program ran this long. */
    public function taking(Seconds $took): self
    {
        return clone($this, ['took' => $took]);
    }

    /** How long it ran, from its start to its end. */
    public function took(): Seconds
    {
        return $this->took;
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
