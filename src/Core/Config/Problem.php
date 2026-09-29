<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** One mistake in a config, at its path: `trees[1].floor`, `channel`. */
final readonly class Problem
{
    private function __construct(private string $path, private string $message) {}

    public static function at(string $path, string $message): self
    {
        return new self($path, $message);
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
