<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_any;
use function count;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\ProvingRange;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ProofStore;

use function rawurlencode;
use function sprintf;

/**
 * GitHub's word on which commits of the default branch a pull request
 * already proved: a commit is proved where its tree is the head of a pull
 * request merged into the repository's default branch whose verdict passed on
 * that head, which is where the latest
 * check-run GitHub Actions completed there under the name `ci.check` concluded
 * in success, and the pull request's own ledger records the head as passed
 * under that check with no proof of its own scope used. Without the ledgers
 * to read, nothing is proved.
 */
final readonly class MergedHeads
{
    /** The GitHub App the check-runs of a workflow's jobs belong to. */
    private const string ACTIONS = 'github-actions';

    private function __construct(
        private Api $api,
        private string $repository,
        private string $head,
        private string $check,
        private ProofStore|NoLedgers $ledgers,
    ) {
    }

    /** The heads a repository merged, asked through its API, up to this head, under this check-run. */
    public static function of(Api $api, string $repository, string $head, string $check): self
    {
        return new self($api, $repository, $head, $check, NoLedgers::none());
    }

    /** These heads, reading each pull request's own ledger from this store. */
    public function trusting(ProofStore $ledgers): self
    {
        return new self($this->api, $this->repository, $this->head, $this->check, $ledgers);
    }

    /** The commit the run is at. */
    public function head(): Revision
    {
        return Revision::ref($this->head);
    }

    /** Whether every commit from a base to the head is the tree of a pull request whose run passed. */
    public function provedSince(Revision $base): bool
    {
        $comparison = $this->api->get(sprintf(
            '/repos/%s/compare/%s...%s',
            $this->repository,
            rawurlencode($base->name()),
            rawurlencode($this->head),
        ));

        if ($comparison instanceof CannotTell) {
            return false;
        }

        $commits = $comparison->items('commits');

        return count($commits) <= ProvingRange::standard()->farthest()
            && count($commits) === $comparison->number('total_commits')
            && $this->provesAll($commits);
    }

    /** @param list<Answer> $commits */
    private function provesAll(array $commits): bool
    {
        $proved = true;

        foreach ($commits as $commit) {
            $proved = $proved && $this->proves($commit);
        }

        return $proved;
    }

    /** Whether a commit's tree is the head of a pull request merged into the default branch that passed on it. */
    private function proves(Answer $commit): bool
    {
        $pulls = $this->api->get(sprintf('/repos/%s/commits/%s/pulls', $this->repository, $commit->text('sha')));
        $tree = $commit->text('commit', 'tree', 'sha');

        return $pulls instanceof Answer && $tree !== '' && array_any(
            $pulls->items(),
            fn(Answer $pull): bool => $pull->text('merged_at') !== ''
                && $this->isIntoDefaultBranch($pull)
                && $this->hasTree($pull->text('head', 'sha'), $tree)
                && $this->passed($pull->number('number'), $pull->text('head', 'sha')),
        );
    }

    /**
     * Whether a pull request was merged into this repository's default branch, as the repository's own API says:
     * a merge into another branch, which no branch protection may guard, proves nothing of the default branch.
     */
    private function isIntoDefaultBranch(Answer $pull): bool
    {
        $branch = $pull->text('base', 'ref');

        return $branch !== ''
            && $branch === $pull->text('base', 'repo', 'default_branch')
            && $pull->text('base', 'repo', 'full_name') === $this->repository;
    }

    /** Whether a commit is of this tree. */
    private function hasTree(string $commit, string $tree): bool
    {
        $answer = $this->api->get(sprintf('/repos/%s/git/commits/%s', $this->repository, $commit));

        return $answer instanceof Answer && $answer->text('tree', 'sha') === $tree;
    }

    /**
     * Whether a pull request's verdict passed at this head: its check-run
     * concluded in success there, and its own ledger records the head as
     * passed under that check with no proof of its own scope used.
     */
    private function passed(int $pullRequest, string $head): bool
    {
        return $pullRequest > 0 && $this->checkedAt($head) && $this->recordedAt($pullRequest, $head);
    }

    /**
     * Whether the latest check-run GitHub Actions completed on this head under
     * the name `ci.check` concluded in success, so that a failed re-run
     * outweighs an earlier success.
     */
    private function checkedAt(string $head): bool
    {
        $runs = $this->api->get(sprintf(
            '/repos/%s/commits/%s/check-runs?check_name=%s&status=completed',
            $this->repository,
            $head,
            rawurlencode($this->check),
        ));

        $latest = 0;
        $conclusion = '';

        foreach ($runs instanceof Answer ? $runs->items('check_runs') : [] as $run) {
            $later = $this->isVerdict($run) && $run->number('id') > $latest;
            $latest = $later ? $run->number('id') : $latest;
            $conclusion = $later ? $run->text('conclusion') : $conclusion;
        }

        return $conclusion === 'success';
    }

    /** Whether a check-run is the verdict's: named `ci.check`, and run by GitHub Actions. */
    private function isVerdict(Answer $run): bool
    {
        return $run->text('name') === $this->check && $run->text('app', 'slug') === self::ACTIONS;
    }

    /**
     * Whether a pull request's own ledger records this head as passed under
     * the check, using none of its own proofs; not where it cannot be read.
     */
    private function recordedAt(int $pullRequest, string $head): bool
    {
        $ledger = $this->ledgers instanceof ProofStore
            ? $this->ledgers->read(Scope::pullRequest($pullRequest))
            : NoLedgers::none();
        $passed = $ledger instanceof Ledger ? $ledger->lastPassed() : $ledger;

        return $passed instanceof Passed
            && $passed->commit()->name() === $head
            && $passed->check() === $this->check
            && ! $passed->usedOwnScope();
    }
}
