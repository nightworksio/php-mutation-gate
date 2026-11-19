<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_any;
use function sprintf;
use function str_ends_with;

/** Files phpstan.neon lists, each relative to the repository. */
final readonly class ListedFiles
{
    /** @param list<string> $listed files, relative to the repository */
    public static function hold(array $listed, string $file): bool
    {
        return array_any($listed, static fn(string $named): bool => str_ends_with($file, sprintf('/%s', $named)));
    }
}
