<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as Composer;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function sprintf;

/** The exact versions of the packages the Pest adapter drives, as Composer installed them. */
final readonly class Installed
{
    /** The packages whose versions decide how Pest mutates and judges. */
    private const array DRIVEN = [
        'pestphp/pest',
        'pestphp/pest-plugin-mutate',
        'phpunit/phpunit',
        'phpunit/php-code-coverage',
    ];

    public static function versionsIn(string $manifest): Versions|CannotJudge
    {
        $file = Path::of($manifest);
        $installed = is_file($manifest)
            ? Composer::decode(Contents::of(sprintf('%s', file_get_contents($manifest))), $file)
            : Composer::missingAt($file);

        return $installed instanceof CannotJudge ? $installed : $installed->drivenBy('Pest', ...self::DRIVEN);
    }
}
