<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What a process a runner's shell started left behind: how it ended, the
 * code it exited with where the shell read one, what it printed on both of
 * its streams, and how long it took where the shell measured it.
 */
final readonly class Ran
{
    private const string UNTIMED = 'The run was not timed, so how long it took cannot be told.';

    private function __construct(
        private string $output,
        private Ending $ending,
        private int|NotGiven $code,
        private Seconds|Unmeasured $took,
    ) {
    }

    public static function finished(bool $succeeded, string $output): self
    {
        return new self(
            $output,
            $succeeded ? Ending::Succeeded : Ending::Failed,
            NotGiven::value(),
            Unmeasured::duration(),
        );
    }

    /**
     * A process that exited with this code, which succeeded where it is 0; one
     * whose code the shell could not read did not succeed.
     */
    public static function exited(int|NotGiven $code, string $output): self
    {
        return new self($output, $code === 0 ? Ending::Succeeded : Ending::Failed, $code, Unmeasured::duration());
    }

    public static function stopped(string $output): self
    {
        return new self($output, Ending::Stopped, NotGiven::value(), Unmeasured::duration());
    }

    /** This, having taken so long. */
    public function took(Seconds $took): self
    {
        return new self($this->output, $this->ending, $this->code, $took);
    }

    public function output(): string
    {
        return $this->output;
    }

    public function ending(): Ending
    {
        return $this->ending;
    }

    /** The code the process exited with; none where it was stopped, could not start, or the shell read none. */
    public function exitCode(): int|NotGiven
    {
        return $this->code;
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

    /** How long it took, where the shell measured it. */
    public function timed(): Seconds|CannotJudge
    {
        return $this->took instanceof Seconds ? $this->took : CannotJudge::because(self::UNTIMED);
    }
}
