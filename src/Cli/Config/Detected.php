<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_column;
use function array_filter;
use function array_flip;
use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use function json_validate;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * What zero-config finds out about a project (ADR-0002): its preset, from
 * what its `composer.json` requires, and its runner, from what is installed.
 */
final readonly class Detected
{
    /** The package that says each preset fits, in the order they are asked about (ADR-0008). */
    private const array PRESETS = ['laravel' => 'laravel/framework', 'symfony' => 'symfony/framework-bundle'];

    private const string PEST = 'pestphp/pest-plugin-mutate';

    private const string INFECTION = 'infection/infection';

    public function __construct(private Directory $project, private Directory $vendor)
    {
    }

    /** `laravel` when `composer.json` requires `laravel/framework`, `symfony` for the bundle, else `library`. */
    public function preset(): string|CannotJudge
    {
        $manifest = $this->decoded($this->project, 'composer.json');

        if ($manifest instanceof CannotJudge) {
            return $manifest;
        }

        $required = is_array($manifest) && array_key_exists('require', $manifest) && is_array($manifest['require'])
            ? $manifest['require']
            : [];

        foreach (self::PRESETS as $preset => $package) {
            if (array_key_exists($package, $required)) {
                return $preset;
            }
        }

        return 'library';
    }

    /** `pest` when Pest's mutation plugin is installed, `infection` when Infection is; both is a choice to make. */
    public function runner(): string|CannotJudge
    {
        $installed = $this->decoded($this->vendor, 'composer/installed.json');
        $packages = is_array($installed) && array_key_exists('packages', $installed) && is_array($installed['packages'])
            ? array_flip(array_filter(array_column($installed['packages'], 'name'), is_string(...)))
            : [];
        $pest = array_key_exists(self::PEST, $packages);
        $infection = array_key_exists(self::INFECTION, $packages);

        return match (true) {
            $installed instanceof CannotJudge => $installed,
            $pest && $infection => CannotJudge::because(sprintf(
                'Both %s and %s are installed. Choose one: set runner in the config, or pass --runner.',
                self::PEST,
                self::INFECTION,
            )),
            $pest => 'pest',
            $infection => 'infection',
            default => CannotJudge::because(sprintf(
                'Neither %s nor %s is installed, so nothing can mutate. Install one of them.',
                self::PEST,
                self::INFECTION,
            )),
        };
    }

    /** A JSON file decoded, or nothing when it is not there. */
    private function decoded(Directory $directory, string $file): mixed
    {
        $contents = $directory->read(Path::of($file));

        return match (true) {
            $contents instanceof Contents && json_validate($contents->text()) => json_decode(
                $contents->text(),
                associative: true,
            ),
            $contents instanceof Contents => CannotJudge::because(sprintf('%s is not JSON.', $file)),
            $contents instanceof CannotJudge => $contents,
            default => [],
        };
    }
}
