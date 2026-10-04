<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function fnmatch;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strpbrk;

/** A shell wildcard pattern, as `fnmatch` reads it, which names many files or classes at once. */
final readonly class ShellPattern
{
    /** The characters that make a text a pattern rather than one name. */
    public const string WILDCARDS = '*?[';

    /**
     * Whether a file is a path an analyser's config names, inside it as a
     * directory, or matched by it as a pattern, as PHPStan and Psalm read
     * the paths they analyse; both are spelt from the same base.
     */
    public static function covers(string $path, string $file): bool
    {
        return $file === $path
            || str_starts_with($file, sprintf('%s/', rtrim($path, '/')))
            || (strpbrk($path, self::WILDCARDS) !== false && fnmatch($path, $file));
    }
}
