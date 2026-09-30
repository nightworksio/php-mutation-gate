<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function file;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\Opcache;

use function sprintf;

/**
 * What a mutant's run says of itself in the guard file (ADR-0004 decision
 * 8): the wrapper, in any process of the run, that it served the mutated
 * file, and the extension that opcache could have served a cached original
 * in its place. A run that served no mutated file, or could have served a
 * cached original, says nothing of the mutant.
 */
final readonly class Guard
{
    /** What the extension writes where opcache could serve a cached original. */
    private const string CACHED = 'cached';

    private function __construct(private bool $served, private bool $cached)
    {
    }

    /** What a guard file says; a file that is not there says nothing was served. */
    public static function in(string $file): self
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $said = is_array($lines) ? $lines : [];

        return new self(
            in_array(MutantFile::SERVED, $said, strict: true),
            in_array(self::CACHED, $said, strict: true),
        );
    }

    /** Says in the guard file, where one is named, that opcache could serve a cached original. */
    public static function noting(string|false $file, Opcache $opcache): void
    {
        if (is_string($file) && $file !== '' && $opcache->couldServeTheOriginal()) {
            file_put_contents($file, sprintf("%s\n", self::CACHED), FILE_APPEND | LOCK_EX);
        }
    }

    /** Whether the wrapper served the mutated file. */
    public function served(): bool
    {
        return $this->served;
    }

    /** Whether opcache could have served a cached original in its place. */
    public function cached(): bool
    {
        return $this->cached;
    }
}
