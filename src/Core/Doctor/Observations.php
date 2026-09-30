<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
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
        private Markers|NotGiven $markers,
        private ProjectFiles $files,
        private KeptLedgers|NotGiven $ledgers,
        private DateTimeImmutable|NotGiven $now,
    ) {
    }

    public static function none(): self
    {
        $none = NotGiven::value();

        return new self($none, $none, $none, $none, $none, ProjectFiles::none(), $none, $none);
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

    /** These, with the runner's own ignore markers in the trees. */
    public function withMarkers(Markers $markers): self
    {
        return clone($this, ['markers' => $markers]);
    }

    /** These, with what the project's own files say. */
    public function withFiles(ProjectFiles $files): self
    {
        return clone($this, ['files' => $files]);
    }

    /** These, with every ledger the proof store keeps. */
    public function withLedgers(KeptLedgers $ledgers): self
    {
        return clone($this, ['ledgers' => $ledgers]);
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

    public function now(): DateTimeImmutable|NotGiven
    {
        return $this->now;
    }
}
