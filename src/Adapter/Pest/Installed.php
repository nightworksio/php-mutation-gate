<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Installed as Composer;
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

    private const string UNLISTED
        = '%s does not list %s, so the gate cannot say which Pest judges the mutants. Run composer install.';

    public static function versionsIn(string $manifest): Versions|CannotJudge
    {
        $installed = Composer::fromJson(is_file($manifest) ? sprintf('%s', file_get_contents($manifest)) : '');
        $missing = $installed->missing(...self::DRIVEN);

        return $missing === []
            ? $installed->versionsOf(...self::DRIVEN)
            : CannotJudge::because(sprintf(self::UNLISTED, $manifest, implode(', ', $missing)));
    }
}
