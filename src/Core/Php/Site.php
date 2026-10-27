<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * Where a file reads a value: the file, the lines that run the read, and the
 * token among the file's own. A read runs on its own line, or, in a
 * parameter's default, on the lines of the body of the function it defaults
 * for, which runs it wherever a call leaves the parameter out.
 */
final readonly class Site
{
    private function __construct(
        private Path $file,
        private Line $line,
        private Line $last,
        private int $token,
        private bool $test,
    ) {
    }

    public static function in(Source $source, int $token): self
    {
        $line = $source->lineOf($token);

        return new self($source->path(), $line, $line, $token, $source->isTest());
    }

    /** A read whose own token stands at an index, run by the lines from the first to the last. */
    public static function runBy(Source $source, int $token, Line $first, Line $last): self
    {
        return new self($source->path(), $first, $last, $token, $source->isTest());
    }

    public function file(): Path
    {
        return $this->file;
    }

    /** The first line that runs the read. */
    public function line(): Line
    {
        return $this->line;
    }

    /** The last line that runs the read. */
    public function last(): Line
    {
        return $this->last;
    }

    /** The token that reads the value, by its index among the file's significant tokens. */
    public function token(): int
    {
        return $this->token;
    }

    /** Whether a test file reads it, which then judges the mutant itself. */
    public function isInTest(): bool
    {
        return $this->test;
    }
}
