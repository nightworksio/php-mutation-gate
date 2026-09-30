<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * What an adapter read about a project for the checks, read-only and without
 * running its code, and the day it read it (ADR-0017, decision 9). A part
 * nobody read is not given, and the checks that ask for it find nothing.
 */
final readonly class Observations
{
    private function __construct(
        private RunnerPhp|CannotJudge|NotGiven $php,
        private InstalledRunners|NotGiven $runners,
        private Settings|Invalid|CannotJudge|NotGiven $settings,
        private Trees|Invalid|CannotJudge|NotGiven $trees,
        private GitIgnore|NotGiven $gitIgnore,
        private InfectionConfig|NotGiven $infection,
        private Markers|NotGiven $markers,
        private ComposerSetup|NotGiven $composer,
        private DateTimeImmutable|NotGiven $now,
    ) {
    }

    public static function none(): self
    {
        $none = NotGiven::value();

        return new self($none, $none, $none, $none, $none, $none, $none, $none, $none);
    }

    /** These, with the PHP the runner runs its tests on, or why it could not be read. */
    public function withPhp(RunnerPhp|CannotJudge $php): self
    {
        return clone($this, ['php' => $php]);
    }

    public function withRunners(InstalledRunners $runners): self
    {
        return clone($this, ['runners' => $runners]);
    }

    /** These, with the effective config, or why there is none. */
    public function withSettings(Settings|Invalid|CannotJudge $settings): self
    {
        return clone($this, ['settings' => $settings]);
    }

    /** These, with the trees the tree source found, or why it found none. */
    public function withTrees(Trees|Invalid|CannotJudge $trees): self
    {
        return clone($this, ['trees' => $trees]);
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

    /** These, with the runner's own ignore markers in the trees. */
    public function withMarkers(Markers $markers): self
    {
        return clone($this, ['markers' => $markers]);
    }

    /** These, with how `composer.json` installs the project's packages. */
    public function withComposer(ComposerSetup $composer): self
    {
        return clone($this, ['composer' => $composer]);
    }

    /** These, as of the instant they were read. */
    public function at(DateTimeImmutable $now): self
    {
        return clone($this, ['now' => $now]);
    }

    public function php(): RunnerPhp|CannotJudge|NotGiven
    {
        return $this->php;
    }

    public function runners(): InstalledRunners|NotGiven
    {
        return $this->runners;
    }

    public function settings(): Settings|Invalid|CannotJudge|NotGiven
    {
        return $this->settings;
    }

    public function trees(): Trees|Invalid|CannotJudge|NotGiven
    {
        return $this->trees;
    }

    public function gitIgnore(): GitIgnore|NotGiven
    {
        return $this->gitIgnore;
    }

    public function infection(): InfectionConfig|NotGiven
    {
        return $this->infection;
    }

    public function markers(): Markers|NotGiven
    {
        return $this->markers;
    }

    public function composer(): ComposerSetup|NotGiven
    {
        return $this->composer;
    }

    public function now(): DateTimeImmutable|NotGiven
    {
        return $this->now;
    }
}
