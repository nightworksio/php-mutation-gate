<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;

/** Where a file reads a value: the file, the line, and the token among the file's own. */
final readonly class Site
{
    private function __construct(private Path $file, private Line $line, private int $token, private bool $test)
    {
    }

    public static function in(Source $source, int $token): self
    {
        return new self($source->path(), $source->lineOf($token), $token, $source->isTest());
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function line(): Line
    {
        return $this->line;
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
