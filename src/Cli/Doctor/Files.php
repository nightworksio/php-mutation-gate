<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use function is_string;

use NightWorksIO\MutationGate\Adapter\Composer\Disk;
use NightWorksIO\MutationGate\Adapter\Infection\Importable;
use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Definitions;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;
use function str_contains;
use function str_ends_with;

/**
 * What `doctor` reads of the project's own files: its `.gitignore`, its
 * Infection config, how its `composer.json` installs its packages, the CI
 * definitions that run the gate, and the baseline the config names.
 */
final readonly class Files
{
    private function __construct(private Disk $disk)
    {
    }

    public static function in(string $project): self
    {
        return new self(Disk::at(Root::of($project)));
    }

    public function of(Settings|Invalid|CannotJudge $settings): ProjectFiles
    {
        $files = ProjectFiles::none()
            ->withGitIgnore(GitIgnore::of($this->text(Path::of(GitIgnore::FILE))))
            ->withRunningTheGate($this->runningTheGate());
        $composer = $this->composer();
        $files = $composer instanceof ComposerSetup ? $files->withComposer($composer) : $files;
        $infection = $this->infection();
        $files = $infection instanceof InfectionConfig ? $files->withInfection($infection) : $files;

        return $settings instanceof Settings
            ? $files->withBaseline($this->baseline($settings->floors()->baseline()))
            : $files;
    }

    /** Every CI definition that names the gate, which a definition that runs it does. */
    private function runningTheGate(): Paths
    {
        $running = [];

        foreach (Definitions::places() as $place) {
            $definitions = str_ends_with($place, '/')
                ? $this->disk->files(sprintf('%s*', $place))
                : [Path::of($place)];

            foreach ($definitions as $definition) {
                $runs = str_contains($this->text($definition), ThisPackage::NAME);
                $running = $runs ? [...$running, $definition] : $running;
            }
        }

        return Paths::of(...$running);
    }

    /** The baseline, empty where there is none, or why it cannot be read. */
    private function baseline(Path $file): Baseline|CannotJudge
    {
        $text = $this->disk->read($file);

        return match (true) {
            $text instanceof Missing => Baseline::none(),
            $text instanceof CannotJudge => $text,
            default => BaselineFile::decode($text, $file),
        };
    }

    /** Every directory a path repository of `composer.json` copies into the vendor directory, where it has one. */
    private function composer(): ComposerSetup|Missing|CannotJudge
    {
        $manifest = $this->disk->manifestIn(Path::root());

        if (! $manifest instanceof Manifest) {
            return $manifest;
        }

        $mirrored = Paths::none();

        foreach ($manifest->mirroredRepositories() as $repository) {
            foreach ($this->disk->directories($repository->value()) as $directory) {
                $mirrored = $mirrored->with($directory);
            }
        }

        return ComposerSetup::of($mirrored);
    }

    /** The first Infection config in the project's root, as Infection looks for it, where it has one. */
    private function infection(): InfectionConfig|NotGiven
    {
        foreach (OwnConfig::files() as $file) {
            $text = $this->disk->read($file);
            $config = is_string($text) ? Importable::in($file->value(), $text) : $text;

            if ($config instanceof InfectionConfig) {
                return $config;
            }
        }

        return NotGiven::value();
    }

    /** What a file holds, nothing where it is not there or cannot be read. */
    private function text(Path $file): string
    {
        $text = $this->disk->read($file);

        return is_string($text) ? $text : '';
    }
}
