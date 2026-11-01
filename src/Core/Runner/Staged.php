<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function sprintf;

/**
 * Where a file one process writes for another to read while both run is
 * written first (ADR-0023): beside its own name, under this one, then moved
 * into place, so a reader finds it whole or not at all.
 *
 * @internal
 */
final readonly class Staged
{
    private const string WRITING = '%s.writing';

    /** The name the file is written under before it is moved to its own. */
    public static function beside(string $file): string
    {
        return sprintf(self::WRITING, $file);
    }
}
