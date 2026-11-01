<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function is_executable;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function realpath;

/**
 * The gate as this process was started (ADR-0022): from its PHAR, named
 * from the project's root where the PHAR is inside the project, and run by
 * PHP where the file cannot run itself; else as Composer linked it into the
 * project's binaries. It takes the project as the console spells it.
 *
 * @internal
 */
final readonly class StartedGate
{
    public static function in(string $project): GateCommand
    {
        return self::from($project, OwnArchive::running());
    }

    /** The gate started from this archive, or, where none, as Composer linked it. */
    public static function from(string $project, OwnArchive|NotGiven $archive): GateCommand
    {
        return $archive instanceof OwnArchive
            ? self::archived($project, $archive)
            : GateCommand::at(ComposerVendor::binaries($project)->child(Path::of(ThisPackage::NAME)));
    }

    private static function archived(string $project, OwnArchive $archive): GateCommand
    {
        $root = Path::of(self::resolved($project));
        $file = Path::of(self::resolved($archive->file()));
        $spelt = $file->within($root) ? $file->relativeTo($root) : $file;

        return is_executable($archive->file()) ? GateCommand::at($spelt) : GateCommand::byPhp($spelt);
    }

    /** A path with every link in it followed, so two spellings of one place compare equal; as given where none is. */
    private static function resolved(string $path): string
    {
        $real = realpath($path);

        return $real === false ? $path : $real;
    }
}
