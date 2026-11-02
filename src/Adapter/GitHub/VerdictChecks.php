<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function in_array;

use NightWorksIO\MutationGate\Core\NotGiven;

use function rawurlencode;
use function sprintf;
use function str_starts_with;

/**
 * The verdict's check-runs on a commit, as GitHub lists them: those GitHub
 * Actions completed under the name `ci.check`. The latest of them decides,
 * so a failed re-run outweighs an earlier success. A check-run is a hint,
 * not a proof: any workflow run at that commit can make one under any name
 * (ADR-0005).
 */
final readonly class VerdictChecks
{
    /** The GitHub App the check-runs of a workflow's jobs belong to. */
    private const string ACTIONS = 'github-actions';

    private const string RUNS = '/repos/%s/commits/%s/check-runs?check_name=%s&status=completed';

    private const string SUITES = '/repos/%s/commits/%s/check-suites?per_page=100';

    private function __construct(private Api $api, private string $repository, private string $check)
    {
    }

    /** The check-runs named so of a repository, asked through its API. */
    public static function of(Api $api, string $repository, string $check): self
    {
        return new self($api, $repository, $check);
    }

    /** Whether the latest verdict on this commit concluded in success. */
    public function passedAt(string $commit): bool
    {
        return $this->latestPassed($commit, NotGiven::value());
    }

    /**
     * Whether the latest verdict on this commit of a check suite GitHub Actions ran on a merge queue's branch
     * concluded in success: a merge group's verdict on its own commit (ADR-0049).
     */
    public function passedInQueueAt(string $commit): bool
    {
        $suites = $this->api->get(sprintf(self::SUITES, $this->repository, $commit));
        $queued = [];

        foreach ($suites instanceof Answer ? $suites->items('check_suites') : [] as $suite) {
            $onQueue = $suite->text('app', 'slug') === self::ACTIONS
                && str_starts_with($suite->text('head_branch'), QueueBranch::PREFIX);
            $queued = $onQueue ? [...$queued, $suite->number('id')] : $queued;
        }

        return $this->latestPassed($commit, $queued);
    }

    /**
     * Whether the latest verdict on this commit, of these check suites where they are given, concluded in success.
     *
     * @param list<int>|NotGiven $suites
     */
    private function latestPassed(string $commit, array|NotGiven $suites): bool
    {
        $runs = $this->api->get(sprintf(self::RUNS, $this->repository, $commit, rawurlencode($this->check)));
        $latest = 0;
        $succeeded = false;

        foreach ($runs instanceof Answer ? $runs->items('check_runs') : [] as $run) {
            $later = $this->counts($run, $suites) && $run->number('id') > $latest;
            $latest = $later ? $run->number('id') : $latest;
            $succeeded = $later ? $run->text('conclusion') === 'success' : $succeeded;
        }

        return $succeeded;
    }

    /**
     * Whether a check-run is the verdict's, named `ci.check` and run by GitHub Actions, of one of these check suites
     * where they are given.
     *
     * @param list<int>|NotGiven $suites
     */
    private function counts(Answer $run, array|NotGiven $suites): bool
    {
        return $run->text('name') === $this->check
            && $run->text('app', 'slug') === self::ACTIONS
            && ($suites instanceof NotGiven || in_array($run->number('check_suite', 'id'), $suites, strict: true));
    }
}
