<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Composer\Disk;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

use function str_replace;

/**
 * Where Composer installed a project's packages, as Composer decides it:
 * `COMPOSER_VENDOR_DIR`, then the manifest's `config.vendor-dir`, then
 * `vendor`. The Pest runner reads Pest there, and `pest:patch` patches it.
 * Composer links each package's commands into its bin directory, where the
 * gate's hooks call the gate.
 */
final readonly class ComposerVendor
{
    /** Where Composer links each installed package's commands, in the vendor directory, by its default bin-dir. */
    public const string BIN = 'bin';

    /** What Composer reads as the vendor directory in a config value. */
    private const string VENDOR_DIR = '{$vendor-dir}';

    /**
     * The vendor directory of the project in this directory, as the project
     * spells it. It takes the project as the console spells it (owner: flows).
     */
    public static function of(string $project): Path
    {
        $overridden = getenv('COMPOSER_VENDOR_DIR');
        $manifest = self::manifest($project);

        return match (true) {
            is_string($overridden) && $overridden !== '' => Path::of($overridden),
            $manifest instanceof Manifest => $manifest->directories()->vendor(),
            default => Path::of(Manifest::VENDOR),
        };
    }

    /**
     * The vendor directory of the project in this directory, where it is on
     * disk. It takes the project as the console spells it (owner: flows).
     */
    public static function on(string $project): string
    {
        return Root::of($project)->at(self::of($project))->value();
    }

    /**
     * Where Composer links the commands of the project in this directory, as
     * the project spells it: `COMPOSER_BIN_DIR`, then the manifest's
     * `config.bin-dir`, in either of which `{$vendor-dir}` is the vendor
     * directory, then `bin` in the vendor directory. It takes the project as
     * the console spells it (owner: hooks).
     */
    public static function binaries(string $project): Path
    {
        $overridden = getenv('COMPOSER_BIN_DIR');
        $manifest = self::manifest($project);
        $declared = match (true) {
            is_string($overridden) && $overridden !== '' => $overridden,
            $manifest instanceof Manifest => $manifest->directories()->bin(),
            default => '',
        };
        $vendor = self::of($project);

        return $declared === ''
            ? $vendor->child(Path::of(self::BIN))
            : Path::of(str_replace(self::VENDOR_DIR, $vendor->value(), $declared))->collapsed();
    }

    private static function manifest(string $project): Manifest|Missing|CannotJudge
    {
        return Disk::at(Root::of($project))->manifestIn(Path::root());
    }
}
