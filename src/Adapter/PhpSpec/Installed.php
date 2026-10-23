<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as Composer;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Floors;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function sprintf;

/**
 * The exact versions of the packages the PhpSpec runner drives, as Composer
 * installed them, where each is one the runner can drive (ADR-0027):
 * PhpSpec at its floor or later, and php-code-coverage at the release whose
 * map the native runner reads, which the runner measures each example's
 * coverage with (see Floors).
 */
final readonly class Installed
{
    /** The first PhpSpec the runner drives. */
    private const string FLOOR = '8.2.0';

    public static function versionsIn(string $manifest): Versions|CannotJudge
    {
        $floors = Floors::of(BuiltinRunner::PhpSpec, self::FLOOR);
        $file = Path::of($manifest);
        $installed = is_file($manifest)
            ? Composer::decode(Contents::of(sprintf('%s', file_get_contents($manifest))), $file)
            : Composer::missingAt($file);
        $versions = $installed instanceof CannotJudge
            ? $installed
            : $installed->drivenBy(Program::PhpSpec, ...$floors->driven());

        return $versions instanceof Versions ? $floors->held($versions, $manifest) : $versions;
    }
}
