<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_values;
use function explode;

use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Import\Import;

use function str_starts_with;

/** What an import says, as a test reads it. */
final class Imports
{
    /** @return list<string> the line of each key the report lists, as the report indents it */
    public static function keys(Import $import): array
    {
        return array_values(array_filter(
            explode("\n", $import->report('infection.json5')),
            static fn(string $line): bool => str_starts_with($line, '  '),
        ));
    }

    /** The config an import writes, on one line. */
    public static function written(Import $import): string
    {
        return $import->layer()->written(ProjectRoot::origin())->line();
    }
}
