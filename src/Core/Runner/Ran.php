<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function is_int;

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

    /** What a shell adds to a signal's number for the exit code of a process that signal ended. */
    private const int SIGNALLED = 128;

    /** The highest signal number there is: Linux's SIGRTMAX. */
    private const int LAST_SIGNAL = 64;

    private function __construct(
        private string $output,
        private Ending $ending,
        private int|NotGiven $code,
        private Seconds|Unmeasured $took,
        private string|NotGiven $printed = new NotGiven(),
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
     * whose code the shell could not read did not succeed. A shell that kept
     * its streams apart says what it printed on its standard output alone.
     */
    public static function exited(
        int|NotGiven $code,
        string $output,
        string|NotGiven $printed = new NotGiven(),
    ): self {
        $ending = $code === 0 ? Ending::Succeeded : Ending::Failed;

        return new self($output, $ending, $code, Unmeasured::duration(), $printed);
    }

    /** A process a signal ended, its exit code the one a shell gives it. */
    public static function signalled(int $signal, string $output): self
    {
        return self::exited(self::SIGNALLED + $signal, $output);
    }

    public static function stopped(string $output, string|NotGiven $printed = new NotGiven()): self
    {
        return new self($output, Ending::Stopped, NotGiven::value(), Unmeasured::duration(), $printed);
    }

    /** A process stopped where it made no progress for its silence limit, before its deadline. */
    public static function silenced(string $output, string|NotGiven $printed = new NotGiven()): self
    {
        return new self($output, Ending::Silenced, NotGiven::value(), Unmeasured::duration(), $printed);
    }

    /** This, having taken so long. */
    public function took(Seconds $took): self
    {
        return clone($this, ['took' => $took]);
    }

    /** What it printed on both of its streams, its standard output first. */
    public function output(): string
    {
        return $this->output;
    }

    /**
     * What it printed on its standard output alone, such as a report in JSON;
     * both of its streams where the shell did not keep them apart.
     */
    public function printed(): string
    {
        return $this->printed instanceof NotGiven ? $this->output : $this->printed;
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

    /**
     * Whether a signal ended it, as its exit code says: 128 plus the signal's
     * number. PHP's own exit code for a fatal error, 255, is none.
     */
    public function endedBySignal(): bool
    {
        return is_int($this->code)
            && $this->code > self::SIGNALLED
            && $this->code <= self::SIGNALLED + self::LAST_SIGNAL;
    }

    public function succeeded(): bool
    {
        return $this->ending === Ending::Succeeded;
    }

    /** Whether it was stopped, at its deadline or at its silence limit. */
    public function wasStopped(): bool
    {
        return $this->ending === Ending::Stopped || $this->ending === Ending::Silenced;
    }

    /** Whether it was stopped at its silence limit. */
    public function wasSilenced(): bool
    {
        return $this->ending === Ending::Silenced;
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
