<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Composer\Names;
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
        $contents = $this->project->read(Manifest::fileIn(Path::root()));
        $manifest = $contents instanceof Contents ? Manifest::decode($contents, Path::root()) : $contents;

        if ($manifest instanceof CannotJudge) {
            return $manifest;
        }

        $required = $manifest instanceof Manifest ? $manifest->requiresToRun() : Names::of();

        foreach (self::PRESETS as $preset => $package) {
            if ($required->has($package)) {
                return $preset;
            }
        }

        return 'library';
    }

    /** `pest` when Pest's mutation plugin is installed, `infection` when Infection is; both is a choice to make. */
    public function runner(): string|CannotJudge
    {
        $file = Installed::fileIn(Path::root());
        $contents = $this->vendor->read($file);
        $installed = match (true) {
            $contents instanceof Contents => Installed::decode($contents, $file),
            $contents instanceof CannotJudge => $contents,
            default => Installed::missingAt($file),
        };

        return $installed instanceof CannotJudge ? $installed : $this->runnerIn($installed);
    }

    private function runnerIn(Installed $installed): string|CannotJudge
    {
        $pest = $installed->has(self::PEST);
        $infection = $installed->has(self::INFECTION);

        return match (true) {
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
}
