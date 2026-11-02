<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

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
use function str_starts_with;
use function version_compare;

/**
 * The exact versions of the packages the Testo runner drives, as Composer
 * installed them, where Testo is one the runner can drive (ADR-0027,
 * ADR-0047): Testo of the minor range composer.json allows, which its own
 * interceptors and console are read from, and the coverage plugin that
 * writes its XML coverage. A version that is not a release, such as a
 * branch, is taken as it is.
 */
final readonly class Installed
{
    /** The first Testo the Runner contract suite passed on. */
    private const string FLOOR = '0.10.56';

    /** The minor series of Testo the runner drives, and no other: Testo has no stable release. */
    private const string SERIES = '0.10.';

    private const string OUTSIDE = 'The testo runner drives Testo %s or a later %s* release, and %s holds %s.';

    public static function versionsIn(string $manifest): Versions|CannotJudge
    {
        $file = Path::of($manifest);
        $installed = is_file($manifest)
            ? Composer::decode(Contents::of(sprintf('%s', file_get_contents($manifest))), $file)
            : Composer::missingAt($file);
        $versions = $installed instanceof CannotJudge
            ? $installed
            : $installed->drivenBy(Program::Testo, Package::Testo->value, Package::TestoCodecov->value);

        return $versions instanceof Versions ? self::held($versions, $manifest) : $versions;
    }

    /** These versions, where the Testo among them is in the range the runner drives; or why not. */
    private static function held(Versions $versions, string $manifest): Versions|CannotJudge
    {
        foreach ($versions as $version) {
            $release = $version->release();
            $outside = $version->package() === Package::Testo->value
                && $version->isRelease()
                && (! str_starts_with($release, self::SERIES) || version_compare($release, self::FLOOR, '<'));

            if ($outside) {
                return CannotJudge::because(sprintf(self::OUTSIDE, self::FLOOR, self::SERIES, $manifest, $release));
            }
        }

        return $versions;
    }
}
