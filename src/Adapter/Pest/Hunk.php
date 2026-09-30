<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function str_contains;
use function str_replace;

/** One place in a file of pest-plugin-mutate the patch rewrites: the lines it ships, and what they become. */
final readonly class Hunk
{
    private function __construct(private string $file, private string $ships, private string $becomes)
    {
    }

    /** @param string $file relative to pest-plugin-mutate's source directory */
    public static function in(string $file, string $ships, string $becomes): self
    {
        return new self($file, $ships, $becomes);
    }

    public function file(): string
    {
        return $this->file;
    }

    public function isAppliedTo(string $source): bool
    {
        return str_contains($source, $this->becomes);
    }

    public function fits(string $source): bool
    {
        return str_contains($source, $this->ships);
    }

    public function applyTo(string $source): string
    {
        return str_replace($this->ships, $this->becomes, $source);
    }
}
