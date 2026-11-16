<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\GitHub;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Listed;

/**
 * What `doctor --online` read of the repository on GitHub (ADR-0017):
 * its default branch and the checks it requires, the fork approval
 * policy, and how the gate's workflows run on a schedule. A setting
 * GitHub would not show says why, which the token usually is.
 *
 * @internal
 */
final readonly class GitHubSettings
{
    /** @param Listed<string>|CannotTell $required */
    private function __construct(
        private string $repository,
        private string $defaultBranch,
        private Listed|CannotTell $required,
        private ForkApproval|CannotTell $forkApproval,
        private Schedule|CannotTell $schedule,
        private bool $isPublic,
    ) {
    }

    /** @param Listed<string>|CannotTell $required the check-runs the default branch requires, by name */
    public static function of(
        string $repository,
        string $defaultBranch,
        Listed|CannotTell $required,
        ForkApproval|CannotTell $forkApproval,
        Schedule|CannotTell $schedule,
        bool $isPublic = false,
    ): self {
        return new self($repository, $defaultBranch, $required, $forkApproval, $schedule, $isPublic);
    }

    /** The repository, as `owner/name`. */
    public function repository(): string
    {
        return $this->repository;
    }

    public function defaultBranch(): string
    {
        return $this->defaultBranch;
    }

    /** @return Listed<string>|CannotTell */
    public function required(): Listed|CannotTell
    {
        return $this->required;
    }

    public function forkApproval(): ForkApproval|CannotTell
    {
        return $this->forkApproval;
    }

    public function schedule(): Schedule|CannotTell
    {
        return $this->schedule;
    }

    /** Whether GitHub lists the repository as public, which its hosted arm runners are free for. */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }
}
