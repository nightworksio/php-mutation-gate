<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

/** Each mutated file the gate could read, by its path. A file it could not read holds nothing. */
final readonly class Sources
{
    /** @param array<string, Contents> $files */
    private function __construct(private array $files)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, with what a file holds. */
    public function with(Path $file, Contents $contents): self
    {
        return new self([...$this->files, $file->value() => $contents]);
    }

    public function has(Path $file): bool
    {
        return array_key_exists($file->value(), $this->files);
    }

    /** What a file holds; nothing where the gate could not read it. */
    public function of(Path $file): Contents
    {
        return $this->has($file) ? $this->files[$file->value()] : Contents::of('');
    }
}
