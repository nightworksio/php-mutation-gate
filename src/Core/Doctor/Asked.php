<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What `doctor` was asked to find out beyond what it reads offline
 * (ADR-0017, decision 9): what running the suite measured, under
 * `--measure`, and the repository's settings on GitHub, under `--online`,
 * or why no repository on GitHub was found.
 */
final readonly class Asked
{
    private function __construct(
        private Measurement|NotGiven $measurement,
        private GitHubSettings|CannotTell|NotGiven $gitHub,
    ) {
    }

    public static function nothing(): self
    {
        return new self(NotGiven::value(), NotGiven::value());
    }

    public function withMeasurement(Measurement $measurement): self
    {
        return clone($this, ['measurement' => $measurement]);
    }

    public function withGitHub(GitHubSettings|CannotTell $gitHub): self
    {
        return clone($this, ['gitHub' => $gitHub]);
    }

    public function measurement(): Measurement|NotGiven
    {
        return $this->measurement;
    }

    public function gitHub(): GitHubSettings|CannotTell|NotGiven
    {
        return $this->gitHub;
    }
}
