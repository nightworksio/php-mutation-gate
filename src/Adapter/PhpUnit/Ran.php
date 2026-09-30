<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a process printed, how it ended, with success, with failure or stopped
 * at its deadline, and how long it took.
 */
final readonly class Ran
{
    private function __construct(private string $output, private Ending $ending, private Seconds $took)
    {
    }

    public static function finished(bool $succeeded, string $output, Seconds $took): self
    {
        return new self($output, $succeeded ? Ending::Succeeded : Ending::Failed, $took);
    }

    public static function stopped(string $output, Seconds $took): self
    {
        return new self($output, Ending::Stopped, $took);
    }

    public function took(): Seconds
    {
        return $this->took;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function ending(): Ending
    {
        return $this->ending;
    }
}
