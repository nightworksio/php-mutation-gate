<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/** A shell wildcard pattern, as `fnmatch` reads it, which names many files or classes at once. */
final readonly class ShellPattern
{
    /** The characters that make a text a pattern rather than one name. */
    public const string WILDCARDS = '*?[';
}
