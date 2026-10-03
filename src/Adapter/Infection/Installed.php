<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as Composer;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function sprintf;

/** The exact versions of the packages the Infection adapter drives, as Composer installed them. */
final readonly class Installed
{
    /** The packages whose versions decide how Infection mutates and judges. */
    private const array DRIVEN = ['infection/infection', 'phpunit/phpunit', 'phpunit/php-code-coverage'];

    /** The versions of the packages Infection always drives, and of these others the project's config names. */
    public static function versionsIn(string $manifest, string ...$also): Versions|CannotJudge
    {
        $file = Path::of($manifest);
        $installed = is_file($manifest)
            ? Composer::decode(Contents::of(sprintf('%s', file_get_contents($manifest))), $file)
            : Composer::missingAt($file);

        return $installed instanceof CannotJudge
            ? $installed
            : $installed->drivenBy(Program::Infection, ...self::DRIVEN, ...$also);
    }
}
