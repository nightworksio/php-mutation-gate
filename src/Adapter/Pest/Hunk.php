<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function mb_substr_count;
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

    /** Whether the source carries the patched lines, once. */
    public function isAppliedTo(string $source): bool
    {
        return mb_substr_count($source, $this->becomes) === 1;
    }

    /** Whether the source carries the lines as the release ships them, once, so a patch changes one place. */
    public function fits(string $source): bool
    {
        return mb_substr_count($source, $this->ships) === 1;
    }

    public function applyTo(string $source): string
    {
        return str_replace($this->ships, $this->becomes, $source);
    }

    /** The source without the patched lines, where it carries them. */
    public function takenFrom(string $source): string
    {
        return str_replace($this->becomes, '', $source);
    }
}
