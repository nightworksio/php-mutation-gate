<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A command the gate ran to its end, as the adapter that started it tells
 * it: how it exited, what it wrote on its standard output, and what it said
 * on its error output; or, where it never started, why. Adapters share no
 * code (A3), so each starts its own process, and this is what they have in
 * common.
 */
final readonly class ChildProcess
{
    private const string EXITED = 'exit %d: %s';

    private const string NEVER_STARTED = 'it did not run: %s';

    private function __construct(private int|NotGiven $exit, private string $output, private string $errors)
    {
    }

    public static function exited(int $exit, string $output, string $errors): self
    {
        return new self($exit, $output, $errors);
    }

    /** A command that never started, and why. */
    public static function neverStarted(string $why): self
    {
        return new self(NotGiven::value(), '', $why);
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
        return $this->exit instanceof NotGiven
            ? sprintf(self::NEVER_STARTED, $this->errors)
            : sprintf(self::EXITED, $this->exit, $this->errors);
    }
}
