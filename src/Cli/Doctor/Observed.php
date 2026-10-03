<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Runtime\PhpProbe;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\DeclaredTrees;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Runner;

/**
 * What `doctor`, `plan` and a one-process `run` observe of a project before
 * the checks: its config, its runners, its trees and the runner's markers in
 * them, its own files, the ledgers the proof store keeps, and the PHP the
 * runner starts. Nothing runs the project's code. The runner and the store
 * are the ones a run builds, in the directory the gate runs in, which is the
 * project's root.
 */
final readonly class Observed
{
    public function __construct(
        private string $project,
        private Extensions $extensions,
        private Effective $effective,
        private Detected $detected,
        private PhpProbe $php,
        private DoctorRun $run,
    ) {
    }

    public function of(CommandLine $given): Observations
    {
        $settings = $this->effective->settings($given);
        $observed = Observations::none()
            ->in($this->run)
            ->withSettings($settings)
            ->withPhp($this->phpOf($settings))
            ->withFiles(Files::in($this->project)->of($settings));
        $installed = $this->detected->installed();
        $observed = $installed instanceof Installed ? $observed->withRunners(InstalledRunners::of(
            pest: $installed->has(Package::PestMutate->value),
            infection: $installed->has(Package::Infection->value),
            chosen: $this->effective->choosesRunner($given),
        )) : $observed;

        return $settings instanceof Settings ? $this->withTrees($observed, $settings) : $observed;
    }

    /**
     * These, with the trees the tree source finds, the markers the chosen
     * runner finds in them, and the ledgers the proof store keeps, where it
     * keeps them in a directory, which `doctor` reads offline.
     */
    private function withTrees(Observations $observed, Settings $settings): Observations
    {
        $trees = $this->trees($settings);
        $markers = $trees instanceof Trees ? $this->markers($settings, $trees) : NotGiven::value();
        $store = new Chosen($this->extensions)->proofStore($settings->proofs()->store());
        $observed = $observed->withTrees($trees);
        $observed = $store instanceof LedgerDirectory ? $observed->withLedgers($store->kept()) : $observed;

        return $markers instanceof Markers ? $observed->withMarkers($markers) : $observed;
    }

    /** The chosen runner's own ignore markers in the trees, as a run asks for them before it mutates anything. */
    private function markers(Settings $settings, Trees $trees): Markers|Invalid|CannotJudge
    {
        $runner = new Chosen($this->extensions)->runner($settings->runner()->choice());
        $paths = Paths::none();

        foreach ($trees as $tree) {
            $paths = $paths->with($tree->path());
        }

        return $runner instanceof Runner ? $runner->markers($paths) : $runner;
    }

    /**
     * The PHP the chosen runner starts, with the runner's own options, withholding what every run withholds and
     * every CI's credentials, even where the config cannot be used. The PHPUnit runner turns opcache off on each
     * mutant's command line.
     */
    private function phpOf(Settings|Invalid|CannotJudge $settings): RunnerPhp|CannotJudge
    {
        $chosen = new Chosen($this->extensions);
        $withheld = $settings instanceof Settings
            ? $chosen->withheld($settings->ci(), $settings->runner()->withhold())
            : $chosen->withheld(Ci::none(), Withheld::nothing());
        $runner = $settings instanceof Settings ? $settings->runner()->choice()->use()->value() : '';
        $own = $runner === BuiltinRunner::Infection->value ? OwnConfig::in($this->infectionProject()) : [];
        $php = $this->php->describe($withheld, ...($own instanceof OwnConfig ? $own->phpOptions() : []));

        return $php instanceof RunnerPhp && $runner === BuiltinRunner::PhpUnit->value
            ? $php->turningOpcacheOff()
            : $php;
    }

    private function trees(Settings $settings): Trees|Invalid|CannotJudge
    {
        $source = new Chosen($this->extensions)->treeSource($settings->treeSource());

        return $source instanceof Invalid || $source instanceof CannotJudge
            ? $source
            : new DeclaredTrees($source, $settings->floors()->trees())->trees();
    }



    private function infectionProject(): Project
    {
        return Project::at(Root::of($this->project), Paths::none(), Workspace::root());
    }
}
