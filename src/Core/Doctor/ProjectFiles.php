<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What the project's own files say, as far as the checks ask them: its
 * `.gitignore`, its Infection config, how its `composer.json` installs its
 * packages, which CI definitions run the gate, and its baseline. A file
 * nobody read is not given.
 */
final readonly class ProjectFiles
{
    private function __construct(
        private GitIgnore|NotGiven $gitIgnore,
        private InfectionConfig|NotGiven $infection,
        private ComposerSetup|NotGiven $composer,
        private Paths|NotGiven $runningTheGate,
        private Baseline|CannotJudge|NotGiven $baseline,
        private PhpUnitMemory|NotGiven $phpUnitMemory,
    ) {
    }

    public static function none(): self
    {
        $none = NotGiven::value();

        return new self($none, $none, $none, $none, $none, $none);
    }

    /** These, with the project's `.gitignore`, empty where it has none. */
    public function withGitIgnore(GitIgnore $gitIgnore): self
    {
        return clone($this, ['gitIgnore' => $gitIgnore]);
    }

    /** These, with the project's Infection config, where it has one. */
    public function withInfection(InfectionConfig $infection): self
    {
        return clone($this, ['infection' => $infection]);
    }

    /** These, with how `composer.json` installs the project's packages. */
    public function withComposer(ComposerSetup $composer): self
    {
        return clone($this, ['composer' => $composer]);
    }

    /** These, with every CI definition that runs the gate, none where no definition does. */
    public function withRunningTheGate(Paths $definitions): self
    {
        return clone($this, ['runningTheGate' => $definitions]);
    }

    /** These, with the baseline, empty where there is none, or why it cannot be read. */
    public function withBaseline(Baseline|CannotJudge $baseline): self
    {
        return clone($this, ['baseline' => $baseline]);
    }

    /** These, with the `memory_limit` the project's PHPUnit config sets, where it sets one. */
    public function withPhpUnitMemory(PhpUnitMemory $memory): self
    {
        return clone($this, ['phpUnitMemory' => $memory]);
    }

    public function gitIgnore(): GitIgnore|NotGiven
    {
        return $this->gitIgnore;
    }

    public function infection(): InfectionConfig|NotGiven
    {
        return $this->infection;
    }

    public function composer(): ComposerSetup|NotGiven
    {
        return $this->composer;
    }

    public function runningTheGate(): Paths|NotGiven
    {
        return $this->runningTheGate;
    }

    public function baseline(): Baseline|CannotJudge|NotGiven
    {
        return $this->baseline;
    }

    public function phpUnitMemory(): PhpUnitMemory|NotGiven
    {
        return $this->phpUnitMemory;
    }
}
