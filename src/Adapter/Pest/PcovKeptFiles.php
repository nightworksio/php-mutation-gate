<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function explode;
use function getcwd;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The files whose tests keep pcov as PHP is configured, as `pest.pcovKept`
 * names them by globs and the run was told them (ADR-0185).
 *
 * @internal
 */
final readonly class PcovKeptFiles
{
    private function __construct(private Globs $globs)
    {
    }

    /** Those this process was told, one glob to a line; none where it was told none. */
    public static function told(): self
    {
        $told = getenv(GateVariable::PcovKept->value);
        $globs = is_string($told) && $told !== '' ? explode("\n", $told) : [];

        return new self(Globs::of(...array_map(Glob::of(...), $globs)));
    }

    /** Whether a glob names this file, by its path from the project's root where it is inside it, or as it is. */
    public function names(string $file): bool
    {
        return $this->globs->matches(Path::of($file)->relativeTo(Path::of((string) getcwd())));
    }
}
