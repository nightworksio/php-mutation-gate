<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function in_array;
use function is_string;
use function realpath;

/** The files a PHP process had loaded at one moment, each by its real path, as `get_included_files()` lists them. */
final readonly class Loaded
{
    /** @param list<string> $files */
    private function __construct(private array $files)
    {
    }

    /** @param list<string> $files */
    public static function of(array $files): self
    {
        return new self($files);
    }

    /** Whether the file at a path is among them, by its real path where it has one. */
    public function has(string $path): bool
    {
        $real = realpath($path);

        return in_array(is_string($real) ? $real : $path, $this->files, strict: true);
    }
}
