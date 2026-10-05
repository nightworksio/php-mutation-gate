<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function sprintf;

/** One mistake in a config, at its path: `trees[1].floor`, `channel`. */
final readonly class Problem
{
    private const string MISMATCH = 'expected %s, got %s';

    private function __construct(private string $path, private string $message)
    {
    }

    public static function at(string $path, string $message): self
    {
        return new self($path, $message);
    }

    /** The value at this path is not what it should be: what was expected, and what it got, as written. */
    public static function mismatch(string $path, string $expected, string $got): self
    {
        return new self($path, sprintf(self::MISMATCH, $expected, $got));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function message(): string
    {
        return $this->message;
    }
}
