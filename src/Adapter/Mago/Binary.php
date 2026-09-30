<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Mago;

use function file_get_contents;
use function glob;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * The Mago binary Composer's package downloaded for the version Composer
 * installed (ADR-0020, decision 18). The package downloads it the first
 * time `vendor/bin/mago` runs; the gate runs it, and never downloads it.
 */
final readonly class Binary
{
    /** The Composer package Mago comes in. */
    public const string PACKAGE = 'carthage-software/mago';

    /** Where the package keeps the binary of a version, under the vendor directory. */
    private const string DOWNLOADED = '%1$s/carthage-software/mago/composer/bin/%2$s/mago-%2$s-*/mago';

    private const string NOT_INSTALLED = 'Composer lists no %s in %s: install it, with composer require --dev %1$s.';

    private const string NOT_DOWNLOADED
        = 'Mago %s has not downloaded its binary yet: run vendor/bin/mago --version once, which downloads it.';

    /** The binary in this vendor directory, or why there is none to run. */
    public static function in(string $vendor): string|CannotJudge
    {
        $file = Installed::fileIn(Path::of($vendor));
        $text = is_file($file->value()) ? file_get_contents($file->value()) : false;
        $installed = is_string($text) ? Installed::decode(Contents::of($text), $file) : Installed::missingAt($file);

        if ($installed instanceof CannotJudge) {
            return $installed;
        }

        foreach ($installed->versionsOf(self::PACKAGE) as $version) {
            $found = glob(sprintf(self::DOWNLOADED, $vendor, $version->version()));

            return $found === false || $found === []
                ? CannotJudge::because(sprintf(self::NOT_DOWNLOADED, $version->version()))
                : $found[0];
        }

        return CannotJudge::because(sprintf(self::NOT_INSTALLED, self::PACKAGE, $file->value()));
    }
}
