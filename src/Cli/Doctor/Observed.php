<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Infection\Importable;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Runtime\PhpProbe;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\CiPlan;

/**
 * What `doctor`, `plan` and a one-process `run` observe of a project before
 * the checks: its config, its runners, its trees, its `.gitignore`, its
 * Infection config, and the PHP the runner starts. Nothing runs the
 * project's code.
 */
final readonly class Observed
{
    public function __construct(
        private string $project,
        private Extensions $extensions,
        private Effective $effective,
        private Detected $detected,
        private PhpProbe $php,
    ) {
    }

    public function of(CommandLine $given): Observations
    {
        $settings = $this->effective->settings($given);
        $observed = Observations::none()
            ->withSettings($settings)
            ->withPhp($this->phpOf($settings))
            ->withGitIgnore($this->gitIgnore());
        $installed = $this->detected->installed();
        $observed = $installed instanceof Installed ? $observed->withRunners(InstalledRunners::of(
            pest: $installed->has(Detected::PEST),
            infection: $installed->has(Detected::INFECTION),
            chosen: $this->effective->choosesRunner($given),
        )) : $observed;
        $observed = $settings instanceof Settings ? $observed->withTrees($this->trees($settings)) : $observed;
        $infection = $this->infection();

        return $infection instanceof InfectionConfig ? $observed->withInfection($infection) : $observed;
    }

    /** The PHP the chosen runner starts, with the runner's own options, withholding what every run withholds. */
    private function phpOf(Settings|Invalid|CannotJudge $settings): RunnerPhp|CannotJudge
    {
        $withheld = $settings instanceof Settings ? $this->withheld($settings) : Withheld::standard();
        $infection = $settings instanceof Settings && $settings->runner()->choice()->use() === Infection::RUNNER;
        $own = $infection ? OwnConfig::in($this->infectionProject()) : [];

        return $this->php->describe($withheld, ...($own instanceof OwnConfig ? $own->phpOptions() : []));
    }

    /** What every run withholds: the standard credentials, the CI plan's own, and what the runner's config names. */
    private function withheld(Settings $settings): Withheld
    {
        $choice = $settings->ci()->plan();
        $plan = $choice instanceof Choice ? new Chosen($this->extensions)->ciPlan($choice) : Withheld::nothing();

        return Withheld::standard()
            ->and($plan instanceof CiPlan ? $plan->withheld() : Withheld::nothing())
            ->and($settings->runner()->withhold());
    }

    private function trees(Settings $settings): Trees|Invalid|CannotJudge
    {
        $source = new Chosen($this->extensions)->treeSource($settings->treeSource());

        return $source instanceof Invalid || $source instanceof CannotJudge ? $source : $source->trees();
    }

    private function gitIgnore(): GitIgnore
    {
        $text = Directory::at($this->project)->read(Path::of(GitIgnore::FILE));

        return GitIgnore::of($text instanceof Contents ? $text->text() : '');
    }

    /** The first Infection config in the project's root, as Infection looks for it, where it has one. */
    private function infection(): InfectionConfig|NotGiven
    {
        foreach (OwnConfig::files() as $file) {
            $text = Directory::at($this->project)->read($file);
            $config = $text instanceof Contents ? Importable::in($file->value(), $text->text()) : $text;

            if ($config instanceof InfectionConfig) {
                return $config;
            }
        }

        return NotGiven::value();
    }

    private function infectionProject(): Project
    {
        return Project::at(Root::of($this->project), Paths::none(), Workspace::root());
    }
}
