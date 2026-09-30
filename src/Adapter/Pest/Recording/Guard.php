<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function file_put_contents;
use function get_included_files;
use function getenv;
use function in_array;
use function is_string;
use function json_encode;
use function realpath;

/**
 * What a run the adapter starts on a mutant of a line that is not executable
 * says about itself, written to the file `MUTATION_GATE_GUARD` names once the
 * run ends: whether the original file was loaded before Pest's override
 * started, whether it was loaded at all, and whether opcache could have
 * served a cached original. Each makes the run's result one the gate cannot
 * trust.
 */
final readonly class Guard
{
    /** The variable the adapter names the guard's file in. */
    public const string FILE = 'MUTATION_GATE_GUARD';

    /** The variable that names the file Pest's override replaces. */
    private const string ORIGINAL = 'PEST_MUTATION_TESTING';

    private function __construct(private string $file, private string $original, private bool $before)
    {
    }

    /** Guarding where the adapter named a file and the override an original, by the files loaded so far. */
    public static function fromEnvironment(): self|Off
    {
        return self::watching(getenv(self::FILE), getenv(self::ORIGINAL), get_included_files());
    }

    /** @param list<string> $loaded */
    public static function watching(string|false $file, string|false $original, array $loaded): self|Off
    {
        if (! is_string($file) || $file === '' || ! is_string($original)) {
            return Off::Guarding;
        }

        $real = realpath($original);
        $path = is_string($real) ? $real : $original;

        return new self($file, $path, in_array($path, $loaded, strict: true));
    }

    /**
     * Writes what it saw, given every file loaded by the end of the run.
     *
     * @param list<string> $loaded
     */
    public function write(array $loaded, Opcache $opcache): void
    {
        $seen = [
            'before' => $this->before,
            'loaded' => in_array($this->original, $loaded, strict: true),
            'opcache' => $opcache->couldServeTheOriginal(),
        ];

        file_put_contents($this->file, json_encode($seen, JSON_UNESCAPED_SLASHES));
    }
}
