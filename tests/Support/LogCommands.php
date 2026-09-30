<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_values;
use function ltrim;
use function preg_split;
use function str_contains;
use function str_starts_with;

/** The lines of printed text a CI runner would read a command from: GitHub's `::` and `##[`, and Azure's `##vso[`. */
final class LogCommands
{
    /** @return list<string> */
    public static function in(string $printed): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $printed);

        return array_values(array_filter(
            $lines === false ? [$printed] : $lines,
            static fn(string $line): bool => str_starts_with(ltrim($line), '::')
                || str_contains($line, '##[')
                || str_contains($line, '##vso['),
        ));
    }
}
