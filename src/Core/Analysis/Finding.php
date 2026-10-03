<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * One thing a static analyser reports (ADR-0020, decision 9): the file it
 * sits in, as the project spells it; its code, which PHPStan calls its
 * identifier, Mago its code and Psalm its type; its message; and whether it
 * is an error rather than a lesser level. Its line is not kept, since a
 * mutant moves no finding it causes.
 */
final readonly class Finding
{
    private function __construct(
        private Path $file,
        private string $code,
        private string $message,
        private bool $error,
    ) {
    }

    public static function error(Path $file, string $code, string $message): self
    {
        return new self($file, $code, $message, error: true);
    }

    /** A finding below the error level: a warning, a notice, a hint. */
    public static function lesser(Path $file, string $code, string $message): self
    {
        return new self($file, $code, $message, error: false);
    }

    /** The file it sits in: the mutant's original where it sits in the mutant, which stands in for that file. */
    public function file(): Path
    {
        return $this->file;
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
