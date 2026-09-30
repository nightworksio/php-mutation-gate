<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What a process a runner's shell started left behind: how it ended, what it
 * printed on both of its streams, and how long it took where the shell
 * measured it.
 */
final readonly class Ran
{
    private function __construct(private string $output, private Ending $ending, private Seconds|Unmeasured $took)
    {
    }

    public static function finished(bool $succeeded, string $output): self
    {
        return new self($output, $succeeded ? Ending::Succeeded : Ending::Failed, Unmeasured::duration());
    }

    public static function stopped(string $output): self
    {
        return new self($output, Ending::Stopped, Unmeasured::duration());
    }

    /** This, having taken so long. */
    public function took(Seconds $took): self
    {
        return new self($this->output, $this->ending, $took);
    }

    public function output(): string
    {
        return $this->output;
    }

    public function ending(): Ending
    {
        return $this->ending;
    }

    public function succeeded(): bool
    {
        return $this->ending === Ending::Succeeded;
    }

    public function wasStopped(): bool
    {
        return $this->ending === Ending::Stopped;
    }

    public function duration(): Seconds|Unmeasured
    {
        return $this->took;
    }
}
