<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_any;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Composer\Names;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Config\BuiltinAnalyser;
use NightWorksIO\MutationGate\Core\Config\BuiltinPreset;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;

/**
 * What zero-config finds out about a project (ADR-0002): its preset, from
 * what its `composer.json` requires, and its runner and static analyser,
 * from what is installed.
 */
final readonly class Detected
{
    /** The package that says each preset fits, in the order they are asked about (ADR-0008). */
    private const array PRESETS = [
        Package::Laravel->value => BuiltinPreset::Laravel,
        Package::Symfony->value => BuiltinPreset::Symfony,
    ];

    public function __construct(private Directory $project, private Directory $vendor)
    {
    }

    /** Laravel when `composer.json` requires `laravel/framework`, Symfony for the bundle, else a library. */
    public function preset(): BuiltinPreset|CannotJudge
    {
        $contents = $this->project->read(Manifest::fileIn(Path::root()));
        $manifest = $contents instanceof Contents ? Manifest::decode($contents, Path::root()) : $contents;

        if ($manifest instanceof CannotJudge) {
            return $manifest;
        }

        $required = $manifest instanceof Manifest ? $manifest->requiresToRun() : Names::of();

        foreach (self::PRESETS as $package => $preset) {
            if ($required->has($package)) {
                return $preset;
            }
        }

        return BuiltinPreset::Library;
    }

    /** Pest when its mutation plugin is installed, Infection when Infection is; both is a choice to make. */
    public function runner(): BuiltinRunner|CannotJudge
    {
        $installed = $this->installed();

        return $installed instanceof CannotJudge ? $installed : $this->runnerIn($installed);
    }

    /**
     * The analyser `staticCheck.tool: auto` takes: the first whose command is
     * installed and whose config the project has, or `none`. `doctor` asks
     * this too, so the two can never disagree.
     */
    public function staticChecker(): Name
    {
        foreach (BuiltinAnalyser::cases() as $analyser) {
            if ($this->installs($analyser->value) && $this->hasAny($analyser->configs())) {
                return Name::of($analyser->value);
            }
        }

        return Name::of(StaticCheck::NONE);
    }

    /** What Composer installed in the vendor directory, as its `installed.json` lists it. */
    public function installed(): Installed|CannotJudge
    {
        $file = Installed::fileIn(Path::root());
        $contents = $this->vendor->read($file);

        return match (true) {
            $contents instanceof Contents => Installed::decode($contents, $file),
            $contents instanceof CannotJudge => $contents,
            default => Installed::missingAt($file),
        };
    }

    private function runnerIn(Installed $installed): BuiltinRunner|CannotJudge
    {
        $pest = $installed->has(Package::PestMutate->value);
        $infection = $installed->has(Package::Infection->value);

        return match (true) {
            $pest && $infection => CannotJudge::because(sprintf(
                'Both %s and %s are installed. Choose one: set runner in the config, or pass --runner.',
                Package::PestMutate->value,
                Package::Infection->value,
            )),
            $pest => BuiltinRunner::Pest,
            $infection => BuiltinRunner::Infection,
            default => CannotJudge::because(sprintf(
                'Neither %s nor %s is installed, so nothing can mutate. Install one of them.',
                Package::PestMutate->value,
                Package::Infection->value,
            )),
        };
    }

    /** Whether Composer linked this command into the vendor directory. */
    private function installs(string $command): bool
    {
        return $this->vendor->read(Path::of(ComposerVendor::BIN)->child(Path::of($command))) instanceof Contents;
    }

    /** Whether the project's root has any of these files. */
    private function hasAny(Paths $files): bool
    {
        return array_any([...$files], fn(Path $file): bool => $this->project->read($file) instanceof Contents);
    }
}
