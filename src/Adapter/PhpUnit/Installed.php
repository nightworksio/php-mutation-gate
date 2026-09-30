<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function file_get_contents;
use function is_file;
use function ltrim;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as Composer;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function preg_match;
use function sprintf;
use function version_compare;

/**
 * The exact versions of the packages the PHPUnit runner drives, as Composer
 * installed them, where PHPUnit is one the runner can drive: 13.2.0 or later,
 * the first with `--test-id-filter-file` (ADR-0023 decision 9). A version
 * that is not a release, such as a branch, is taken as it is.
 */
final readonly class Installed
{
    private const string PHPUNIT = 'phpunit/phpunit';

    /** The packages whose versions decide how the runner selects, runs and measures tests. */
    private const array DRIVEN = [self::PHPUNIT, 'phpunit/php-code-coverage'];

    /** The lowest PHPUnit with `--test-id-filter-file`, which selects a mutant's tests by their ids. */
    private const string FLOOR = '13.2.0';

    /** A release's version: digits and dots first, with or without a leading `v`. */
    private const string RELEASE = '/^\d+\.\d+/';

    private const string TOO_OLD
        = 'The phpunit runner needs PHPUnit %s or later, for --test-id-filter-file, and %s holds %s.';

    public static function versionsIn(string $manifest): Versions|CannotJudge
    {
        $file = Path::of($manifest);
        $installed = is_file($manifest)
            ? Composer::decode(Contents::of(sprintf('%s', file_get_contents($manifest))), $file)
            : Composer::missingAt($file);
        $versions = $installed instanceof CannotJudge ? $installed : $installed->drivenBy('PHPUnit', ...self::DRIVEN);

        return $versions instanceof Versions ? self::supported($versions, $manifest) : $versions;
    }

    private static function supported(Versions $versions, string $manifest): Versions|CannotJudge
    {
        foreach ($versions as $version) {
            $release = ltrim($version->version(), 'v');
            $tooOld = $version->package() === self::PHPUNIT
                && preg_match(self::RELEASE, $release) === 1
                && version_compare($release, self::FLOOR, '<');

            if ($tooOld) {
                return CannotJudge::because(sprintf(self::TOO_OLD, self::FLOOR, $manifest, $release));
            }
        }

        return $versions;
    }
}
