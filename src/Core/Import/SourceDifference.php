<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Import;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;

/**
 * Where the directories an imported config mutates differ from those
 * `phpunit.xml`'s `<source>` includes (ADR-0016, decision 1). The gate's
 * `trees` replaces the tree source's list whole, so the difference is said
 * rather than merged.
 */
final readonly class SourceDifference
{
    private const string ONLY = '%s names %s, which %s does not.';

    private const string PHPUNIT = 'phpunit.xml\'s <source>';

    /** This import, noting each path one side names and the other does not; none where either names none. */
    public static function noted(Import $import, string $imported, Paths $directories, Paths $included): Import
    {
        if (count($included) === 0 || count($directories) === 0) {
            return $import;
        }

        $onlyImported = self::missingFrom($directories, $included);
        $onlyIncluded = self::missingFrom($included, $directories);
        $import = $onlyIncluded === [] ? $import : $import->noting(
            sprintf(self::ONLY, self::PHPUNIT, implode(', ', $onlyIncluded), $imported),
        );

        return $onlyImported === [] ? $import : $import->noting(
            sprintf(self::ONLY, $imported, implode(', ', $onlyImported), self::PHPUNIT),
        );
    }

    /** @return list<string> each path of the first that the second does not name */
    private static function missingFrom(Paths $paths, Paths $others): array
    {
        $missing = [];

        foreach ($paths as $path) {
            $missing = $others->has($path) ? $missing : [...$missing, $path->value()];
        }

        return $missing;
    }
}
