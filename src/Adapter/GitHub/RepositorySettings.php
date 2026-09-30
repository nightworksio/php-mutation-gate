<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\ForkApproval;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\Schedule;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function rawurlencode;
use function sprintf;

/**
 * What `doctor --online` reads of a repository through GitHub's API
 * (ADR-0017, decision 9): its default branch; the check-runs that branch
 * requires, by its rulesets and by branch protection; the fork approval
 * policy; and how the workflows that run the gate ran on a schedule. Each
 * setting GitHub would not show says why, and the others are still read.
 */
final readonly class RepositorySettings
{
    /** A workflow GitHub turned off after 60 days without activity in the repository. */
    private const string INACTIVE = 'disabled_inactivity';

    /** The rule a ruleset requires check-runs by, and the member of branch protection that lists them. */
    private const string CHECKS = 'required_status_checks';

    private const string FORK_APPROVAL = '/repos/%s/actions/permissions/fork-pr-contributor-approval';

    private const string UNKNOWN_POLICY = 'GitHub answered the approval policy %s, which the gate does not know.';

    private function __construct(private Api $api, private string $repository)
    {
    }

    /** The repository `owner/name`, read through this API. */
    public static function of(Api $api, RepositoryName $repository): self
    {
        return new self($api, $repository->value());
    }

    /** The settings, given the files of the workflows that run the gate; or why the repository cannot be read. */
    public function read(Paths $gateWorkflows): GitHubSettings|CannotTell
    {
        $repository = $this->api->get(sprintf('/repos/%s', $this->repository));

        return $repository instanceof CannotTell ? $repository : $this->settings(
            $repository->text('default_branch'),
            $gateWorkflows,
        );
    }

    private function settings(string $defaultBranch, Paths $gateWorkflows): GitHubSettings
    {
        return GitHubSettings::of(
            $this->repository,
            $defaultBranch,
            $this->required($defaultBranch),
            $this->forkApproval(),
            $this->schedule($gateWorkflows),
        );
    }

    /** @return Listed<string>|CannotTell the check-runs the branch requires, by a ruleset or by protection */
    private function required(string $branch): Listed|CannotTell
    {
        $rules = $this->api->get(sprintf('/repos/%s/rules/branches/%s', $this->repository, rawurlencode($branch)));
        $protected = $this->api->get(sprintf('/repos/%s/branches/%s', $this->repository, rawurlencode($branch)));
        $protecting = $protected instanceof Answer ? $protected->items('protection', self::CHECKS, 'checks') : [];

        return $rules instanceof CannotTell
            ? $rules
            : Listed::of(...$this->rulesetChecks($rules), ...$this->contexts($protecting));
    }

    /**
     * The check-runs the rules that apply to a branch require.
     *
     * @return list<string>
     */
    private function rulesetChecks(Answer $rules): array
    {
        $checks = [];

        foreach ($rules->items() as $rule) {
            $required = $rule->text('type') === self::CHECKS ? $rule->items('parameters', self::CHECKS) : [];
            $checks = [...$checks, ...$this->contexts($required)];
        }

        return $checks;
    }

    /**
     * @param  list<Answer> $checks
     * @return list<string>
     */
    private function contexts(array $checks): array
    {
        $contexts = [];

        foreach ($checks as $check) {
            $contexts[] = $check->text('context');
        }

        return $contexts;
    }

    private function forkApproval(): ForkApproval|CannotTell
    {
        $answer = $this->api->get(sprintf(self::FORK_APPROVAL, $this->repository));

        if ($answer instanceof CannotTell) {
            return $answer;
        }

        $policy = $answer->text('approval_policy');

        return ForkApproval::tryFrom($policy) ?? CannotTell::because(sprintf(self::UNKNOWN_POLICY, $policy));
    }

    private function schedule(Paths $gateWorkflows): Schedule|CannotTell
    {
        $listed = $this->api->get(sprintf('/repos/%s/actions/workflows?per_page=100', $this->repository));

        if ($listed instanceof CannotTell) {
            return $listed;
        }

        $known = Paths::none();
        $disabled = Paths::none();
        $lastRun = NotGiven::value();

        foreach ($listed->items('workflows') as $workflow) {
            $path = Path::of($workflow->text('path'));

            if ($gateWorkflows->has($path)) {
                $known = $known->with($path);
                $disabled = $workflow->text('state') === self::INACTIVE ? $disabled->with($path) : $disabled;
                $lastRun = $this->later($lastRun, $this->lastScheduledRun($workflow->number('id')));
            }
        }

        return Schedule::of($known, $disabled, $lastRun);
    }

    private function lastScheduledRun(int $workflow): Instant|NotGiven
    {
        $runs = $this->api->get(sprintf(
            '/repos/%s/actions/workflows/%d/runs?event=schedule&per_page=1',
            $this->repository,
            $workflow,
        ));

        foreach ($runs instanceof Answer ? $runs->items('workflow_runs') : [] as $run) {
            $at = Instant::parse($run->text('created_at'));

            return $at instanceof Instant ? $at : NotGiven::value();
        }

        return NotGiven::value();
    }

    private function later(Instant|NotGiven $one, Instant|NotGiven $other): Instant|NotGiven
    {
        return match (true) {
            ! $one instanceof Instant => $other,
            ! $other instanceof Instant => $one,
            default => $other->isAfter($one) ? $other : $one,
        };
    }
}
