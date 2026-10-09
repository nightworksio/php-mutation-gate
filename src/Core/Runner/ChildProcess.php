<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function is_int;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A command the gate ran to its end, as the adapter that started it tells
 * it: how it exited, what it wrote on its standard output, and what it said
 * on its error output; or, where it never started, why; or that it was
 * stopped at its limit, with what it wrote until then. Adapters share no
 * code (A3), so each starts its own process, and this is what they have in
 * common.
 */
final readonly class ChildProcess
{
    private const string EXITED = 'exit %d: %s';

    private const string NEVER_STARTED = 'it did not run: %s';

    private const string STOPPED = 'it was stopped at its limit: %s';

    private function __construct(
        private int|NotGiven $exit,
        private string $output,
        private string $errors,
        private bool $stopped,
    ) {
    }

    /**
     * What a process the Processes port ran left behind: exited with its
     * code, stopped at its limit, or never started; its report on its
     * standard output, and the rest on its errors.
     */
    public static function of(Ran $ran): self
    {
        $printed = $ran->printed();
        $errors = mb_substr($ran->output(), mb_strlen($printed));
        $code = $ran->exitCode();

        return match (true) {
            $ran->wasStopped() => self::stopped($printed, $errors),
            is_int($code) => self::exited($code, $printed, $errors),
            default => self::neverStarted($ran->output()),
        };
    }

    public static function exited(int $exit, string $output, string $errors): self
    {
        return new self($exit, $output, $errors, stopped: false);
    }

    /** A command that never started, and why. */
    public static function neverStarted(string $why): self
    {
        return new self(NotGiven::value(), '', $why, stopped: false);
    }

    /** A command stopped at its limit, with what it wrote and said until then. */
    public static function stopped(string $output, string $errors): self
    {
        return new self(NotGiven::value(), $output, $errors, stopped: true);
    }

    /** Whether it was stopped at its limit, before it exited. */
    public function wasStopped(): bool
    {
        return $this->stopped;
    }

    /** How it exited, or nothing where it never started. */
    public function exit(): int|NotGiven
    {
        return $this->exit;
    }

    /** What it wrote on its standard output. */
    public function output(): string
    {
        return $this->output;
    }

    /** What it said on its error output, or why it never started. */
    public function errors(): string
    {
        return $this->errors;
    }

    /** Whether it ran and exited with 0. */
    public function succeeded(): bool
    {
        return $this->exit === 0;
    }

    /** How it exited and what it said on its error output, or why it never started, for a message that says why. */
    public function said(): string
    {
        return match (true) {
            $this->stopped => sprintf(self::STOPPED, $this->errors),
            $this->exit instanceof NotGiven => sprintf(self::NEVER_STARTED, $this->errors),
            default => sprintf(self::EXITED, $this->exit, $this->errors),
        };
    }
}
