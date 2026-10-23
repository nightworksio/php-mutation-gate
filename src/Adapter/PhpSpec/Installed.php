<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed as Composer;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function sprintf;
use function version_compare;

/**
 * The exact versions of the packages the PhpSpec runner drives, as Composer
 * installed them, where each is one the runner can drive (ADR-0027):
 * PhpSpec at its floor or later, and php-code-coverage at the release whose
 * map the native runner reads, which the runner measures each example's
 * coverage with. A version that is not a release, such as a branch, is
 * taken as it is.
 */
final readonly class Installed
{
    /** The packages whose versions decide how the runner selects, runs and measures examples. */
    private const array DRIVEN = [Package::PhpSpec->value, Package::CodeCoverage->value];

    /** The first release of each driven package the runner drives, by its name. */
    private const array FLOORS = [Package::PhpSpec->value => '8.2.0', Package::CodeCoverage->value => '14.0.0'];

    private const string TOO_OLD = 'The phpspec runner needs %s %s or later, and %s holds %s.';

    public static function versionsIn(string $manifest): Versions|CannotJudge
    {
        $file = Path::of($manifest);
        $installed = is_file($manifest)
            ? Composer::decode(Contents::of(sprintf('%s', file_get_contents($manifest))), $file)
            : Composer::missingAt($file);
        $versions = $installed instanceof CannotJudge
            ? $installed
            : $installed->drivenBy(Program::PhpSpec, ...self::DRIVEN);

        return $versions instanceof Versions ? self::supported($versions, $manifest) : $versions;
    }

    private static function supported(Versions $versions, string $manifest): Versions|CannotJudge
    {
        foreach ($versions as $version) {
            $floor = self::FLOORS[$version->package()];

            if ($version->isRelease() && version_compare($version->release(), $floor, '<')) {
                return CannotJudge::because(
                    sprintf(self::TOO_OLD, $version->package(), $floor, $manifest, $version->release()),
                );
            }
        }

        return $versions;
    }
}
