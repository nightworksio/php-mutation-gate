<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The name of the directory a mutant's own files are placed in: its id, or
 * a part of that directory where a run beside another of the mutant's needs
 * files of its own (ADR-0203).
 *
 * @internal
 */
final readonly class MutantDirectory
{
    /** The directory of the mutant this id names, or of the part of it this names where one is named. */
    public static function of(string $id, string $part): string
    {
        return $part === '' ? $id : Path::of($id)->child(Path::of($part))->value();
    }
}
