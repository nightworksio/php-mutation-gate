<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Installed as Composer;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function sprintf;

/** The exact versions of the packages the Infection adapter drives, as Composer installed them. */
final readonly class Installed
{
    /** The packages whose versions decide how Infection mutates and judges. */
    private const array DRIVEN = ['infection/infection', 'phpunit/phpunit', 'phpunit/php-code-coverage'];

    private const string UNLISTED
        = '%s does not list %s, so the gate cannot say which Infection judges the mutants. Run composer install.';

    /** The versions of the packages Infection always drives, and of these others the project's config names. */
    public static function versionsIn(string $manifest, string ...$also): Versions|CannotJudge
    {
        $driven = [...self::DRIVEN, ...$also];
        $installed = Composer::fromJson(is_file($manifest) ? sprintf('%s', file_get_contents($manifest)) : '');
        $missing = $installed->missing(...$driven);

        return $missing === []
            ? $installed->versionsOf(...$driven)
            : CannotJudge::because(sprintf(self::UNLISTED, $manifest, implode(', ', $missing)));
    }
}
