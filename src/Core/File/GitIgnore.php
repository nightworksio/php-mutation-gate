<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_flip;
use function array_key_exists;
use function array_map;
use function explode;
use function trim;

/** A `.gitignore`, as far as the gate asks it whether a directory of its own is kept out of git. */
final readonly class GitIgnore
{
    /** The file, in the project's root. */
    public const string FILE = '.gitignore';

    /** @param array<string, int> $lines each line, trimmed of blanks and slashes */
    private function __construct(private array $lines)
    {
    }

    public static function of(string $text): self
    {
        return new self(array_flip(array_map(
            static fn(string $line): string => trim(trim($line), '/'),
            explode("\n", $text),
        )));
    }

    /** Whether a line names the directory, with or without its slashes. */
    public function names(Path $directory): bool
    {
        return array_key_exists(trim($directory->value(), '/'), $this->lines);
    }
}
