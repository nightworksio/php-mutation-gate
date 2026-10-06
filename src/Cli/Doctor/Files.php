<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use function is_dir;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Composer\Disk;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\Infection\Importable;
use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Patch as InfectionPatch;
use NightWorksIO\MutationGate\Adapter\Pest\Patch as PestPatch;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Cli\Flow\ProjectMemoryLimit;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Definitions;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\Patched;
use NightWorksIO\MutationGate\Core\Doctor\PhpUnitMemory;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\SonarSources;
use NightWorksIO\MutationGate\Core\Doctor\WarmRefusal;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Properties;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;
use function str_contains;
use function str_ends_with;
use function trim;

/**
 * What `doctor` reads of the project's own files: its `.gitignore`, its
 * Infection config, how its `composer.json` installs its packages, the CI
 * definitions that run the gate, the baseline the config names, whether git
 * cloned it shallow, why the last run's warm workers forked nothing, and
 * whether its Infection and pest-plugin-mutate carry the gate's patches.
 */
final readonly class Files
{
    private function __construct(
        private Disk $disk,
        private Directory $project,
        private Git $git,
        private string $root,
    ) {
    }

    public static function in(string $project): self
    {
        return new self(Disk::at(Root::of($project)), Directory::at($project), Git::at($project), $project);
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
        $memory = ProjectMemoryLimit::in($this->project, PhpUnitConfig::candidatesIn(Path::root()));
        $files = $memory instanceof PhpUnitMemory ? $files->withPhpUnitMemory($memory) : $files;
        $files = $this->git->isShallow() === true ? $files->shallow() : $files;
        $sonar = SonarSources::in(Properties::decode($this->text(Path::of(SonarSources::FILE))));
        $files = $sonar instanceof SonarSources ? $files->withSonarSources($sonar) : $files;
        $warm = trim($this->text(WarmRefusal::file()));
        $files = $warm === '' ? $files : $files->withWarmRefusal(WarmRefusal::of($warm));

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

        return ComposerSetup::of($mirrored, $this->infectionPatch(), $this->pestPatch());
    }

    /** Whether the Infection installed in the project's vendor directory carries `infection:patch`. */
    private function infectionPatch(): Patched
    {
        $vendor = ComposerVendor::on($this->root);

        return match (true) {
            ! is_dir(sprintf('%s/%s', $vendor, Package::Infection->value)) => Patched::NotInstalled,
            InfectionPatch::isAppliedIn($vendor) => Patched::Applied,
            default => Patched::Missing,
        };
    }

    /** Whether the pest-plugin-mutate installed in the project's vendor directory carries `pest:patch`. */
    private function pestPatch(): Patched
    {
        $vendor = ComposerVendor::on($this->root);

        return match (true) {
            ! is_dir(sprintf('%s/%s', $vendor, Package::PestMutate->value)) => Patched::NotInstalled,
            PestPatch::isAppliedIn($vendor) => Patched::Applied,
            default => Patched::Missing,
        };
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
