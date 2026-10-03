<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function str_contains;

/** The package's own source: a file under a `src` directory that is neither a test nor a dependency's. */
final readonly class OwnSource
{
    public static function holds(string $file): bool
    {
        return str_contains($file, '/src/') && ! str_contains($file, '/tests/') && ! str_contains($file, '/vendor/');
    }
}
