<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

/**
 * One thing a static analyser reports about a file (ADR-0020, decision 9):
 * its code, which PHPStan calls its identifier, Mago its code and Psalm its
 * type; its message; and whether it is an error rather than a lesser level.
 * Its line is not kept, since a mutant moves no finding it causes.
 */
final readonly class Finding
{
    private function __construct(private string $code, private string $message, private bool $error)
    {
    }

    public static function error(string $code, string $message): self
    {
        return new self($code, $message, error: true);
    }

    /** A finding below the error level: a warning, a notice, a hint. */
    public static function lesser(string $code, string $message): self
    {
        return new self($code, $message, error: false);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isError(): bool
    {
        return $this->error;
    }

    /** Whether this is the same finding as another: the same code and the same message, wherever it is. */
    public function equals(self $other): bool
    {
        return $this->code === $other->code && $this->message === $other->message;
    }
}
