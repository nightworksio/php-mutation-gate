<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * What an adapter read about a project for the checks, read-only and without
 * running its code, and the day it read it (ADR-0017); and what
 * `--measure` and `--online` found out beyond that. A part nobody read is
 * not given, and the checks that ask for it find nothing.
 */
final readonly class Observations
{
    private function __construct(
        private RunnerPhp|CannotJudge|NotGiven $php,
        private InstalledRunners|NotGiven $runners,
        private Settings|Invalid|CannotJudge|NotGiven $settings,
        private Trees|Invalid|CannotJudge|NotGiven $trees,
        private Markers|NotGiven $markers,
        private ProjectFiles $files,
        private KeptLedgers|NotGiven $ledgers,
        private Asked $asked,
        private DoctorRun|NotGiven $run,
    ) {
    }

    public static function none(): self
    {
        $none = NotGiven::value();

        return new self($none, $none, $none, $none, $none, ProjectFiles::none(), $none, Asked::nothing(), $none);
    }

    /** These, with the PHP the runner runs its tests on, or why it could not be read. */
    public function withPhp(RunnerPhp|CannotJudge $php): self
    {
        return new self(
            $php,
            $this->runners,
            $this->settings,
            $this->trees,
            $this->markers,
            $this->files,
            $this->ledgers,
            $this->asked,
            $this->run,
        );
    }

    public function withRunners(InstalledRunners $runners): self
    {
        return new self(
            $this->php,
            $runners,
            $this->settings,
            $this->trees,
            $this->markers,
            $this->files,
            $this->ledgers,
            $this->asked,
            $this->run,
        );
    }

    /** These, with the effective config, or why there is none. */
    public function withSettings(Settings|Invalid|CannotJudge $settings): self
    {
        return new self(
            $this->php,
            $this->runners,
            $settings,
            $this->trees,
            $this->markers,
            $this->files,
            $this->ledgers,
            $this->asked,
            $this->run,
        );
    }

    /** These, with the trees the tree source found, or why it found none. */
    public function withTrees(Trees|Invalid|CannotJudge $trees): self
    {
        return new self(
            $this->php,
            $this->runners,
            $this->settings,
            $trees,
            $this->markers,
            $this->files,
            $this->ledgers,
            $this->asked,
            $this->run,
        );
    }

    /** These, with the runner's own ignore markers in the trees. */
    public function withMarkers(Markers $markers): self
    {
        return new self(
            $this->php,
            $this->runners,
            $this->settings,
            $this->trees,
            $markers,
            $this->files,
            $this->ledgers,
            $this->asked,
            $this->run,
        );
    }

    /** These, with what the project's own files say. */
    public function withFiles(ProjectFiles $files): self
    {
        return new self(
            $this->php,
            $this->runners,
            $this->settings,
            $this->trees,
            $this->markers,
            $files,
            $this->ledgers,
            $this->asked,
            $this->run,
        );
    }

    /** These, with every ledger the proof store keeps. */
    public function withLedgers(KeptLedgers $ledgers): self
    {
        return new self(
            $this->php,
            $this->runners,
            $this->settings,
            $this->trees,
            $this->markers,
            $this->files,
            $ledgers,
            $this->asked,
            $this->run,
        );
    }

    /** These, with what `--measure` and `--online` found out. */
    public function withAsked(Asked $asked): self
    {
        return new self(
            $this->php,
            $this->runners,
            $this->settings,
            $this->trees,
            $this->markers,
            $this->files,
            $this->ledgers,
            $asked,
            $this->run,
        );
    }

    /** These, as doctor's own run read them: when, and with what memory. */
    public function in(DoctorRun $run): self
    {
        return new self(
            $this->php,
            $this->runners,
            $this->settings,
            $this->trees,
            $this->markers,
            $this->files,
            $this->ledgers,
            $this->asked,
            $run,
        );
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

    public function markers(): Markers|NotGiven
    {
        return $this->markers;
    }

    public function files(): ProjectFiles
    {
        return $this->files;
    }

    public function ledgers(): KeptLedgers|NotGiven
    {
        return $this->ledgers;
    }

    public function asked(): Asked
    {
        return $this->asked;
    }

    public function run(): DoctorRun|NotGiven
    {
        return $this->run;
    }
}
