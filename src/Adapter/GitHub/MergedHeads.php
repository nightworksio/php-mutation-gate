<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_any;
use function count;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\ProvingRange;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ProofStore;

use function rawurlencode;
use function sprintf;

/**
 * GitHub's word on which commits of the default branch a pull request or a
 * merge group already proved. A commit is proved where its tree is the head
 * of a pull request merged into the repository's default branch whose verdict
 * passed on that head, which is where the latest check-run GitHub Actions
 * completed there under the name `ci.check` concluded in success, and the
 * pull request's own ledger records the head as passed under that check with
 * no proof of its own scope used; and where a merge group's verdict passed on
 * that very commit, which is where the latest such check-run of a check suite
 * on a merge queue's branch concluded in success (ADR-0049). A merge group's
 * own head is proved where its tree is the head of the pull request it was
 * built for, into the default branch, whose verdict passed on that head
 * (ADR-0022). Without the ledgers to read, nothing is proved.
 *
 * @internal
 */
final readonly class MergedHeads
{
    private function __construct(
        private Api $api,
        private string $repository,
        private string $head,
        private VerdictChecks $checks,
        private string $check,
        private ProofStore|NoLedgers $ledgers,
        private PullRequestNumber|NotGiven $group,
    ) {
    }

    /** The heads a repository merged, asked through its API, up to this head, under this check-run. */
    public static function of(Api $api, string $repository, string $head, string $check): self
    {
        return new self(
            $api,
            $repository,
            $head,
            VerdictChecks::of($api, $repository, $check),
            $check,
            NoLedgers::none(),
            NotGiven::value(),
        );
    }

    /** These heads, reading each pull request's own ledger from this store. */
    public function trusting(ProofStore $ledgers): self
    {
        return new self(
            $this->api,
            $this->repository,
            $this->head,
            $this->checks,
            $this->check,
            $ledgers,
            $this->group,
        );
    }

    /** These heads, where the head is a merge group's, built for this pull request. */
    public function inGroupFor(PullRequestNumber $pullRequest): self
    {
        return new self(
            $this->api,
            $this->repository,
            $this->head,
            $this->checks,
            $this->check,
            $this->ledgers,
            $pullRequest,
        );
    }

    /** The commit the run is at. */
    public function head(): Revision
    {
        return Revision::ref($this->head);
    }

    /**
     * Whether a merge group's head is the tree of its pull request, whose run passed; for any other head, whether
     * every commit from a base to it is the tree of a pull request whose run passed or a merge group's whose run
     * passed. Never without the pull requests' ledgers to read, and then GitHub is not asked.
     */
    public function provedSince(Revision $base): bool
    {
        $ledgers = $this->ledgers;
        $group = $this->group;

        return match (true) {
            ! $ledgers instanceof ProofStore => false,
            $group instanceof PullRequestNumber => $this->groupProved($group, $ledgers),
            default => $this->rangeProved($base, $ledgers),
        };
    }

    /** Whether every commit from a base to the head is proved. */
    private function rangeProved(Revision $base, ProofStore $ledgers): bool
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
            && $this->provesAll($commits, $ledgers);
    }

    /**
     * Whether a merge group's head is the tree of the head of the pull request it was built for, into the default
     * branch, whose verdict passed on that head.
     */
    private function groupProved(PullRequestNumber $pullRequest, ProofStore $ledgers): bool
    {
        $pull = $this->api->get(sprintf('/repos/%s/pulls/%d', $this->repository, $pullRequest->value()));
        $group = $this->api->get(sprintf('/repos/%s/git/commits/%s', $this->repository, rawurlencode($this->head)));

        if (! $pull instanceof Answer || ! $group instanceof Answer) {
            return false;
        }

        $tree = $group->text('tree', 'sha');

        return $tree !== ''
            && $this->isIntoDefaultBranch($pull)
            && $this->hasTree($pull->text('head', 'sha'), $tree)
            && $this->passed($pullRequest->value(), $pull->text('head', 'sha'), $ledgers);
    }

    /** @param list<Answer> $commits */
    private function provesAll(array $commits, ProofStore $ledgers): bool
    {
        $proved = true;

        foreach ($commits as $commit) {
            $proved = $proved && $this->proves($commit, $ledgers);
        }

        return $proved;
    }

    /**
     * Whether a commit's tree is the head of a pull request merged into the default branch that passed on it, or
     * the commit is a merge group's whose verdict passed on it.
     */
    private function proves(Answer $commit, ProofStore $ledgers): bool
    {
        return $this->merged($commit, $ledgers) || $this->checks->passedInQueueAt($commit->text('sha'));
    }

    /** Whether a commit's tree is the head of a pull request merged into the default branch that passed on it. */
    private function merged(Answer $commit, ProofStore $ledgers): bool
    {
        $pulls = $this->api->get(sprintf('/repos/%s/commits/%s/pulls', $this->repository, $commit->text('sha')));
        $tree = $commit->text('commit', 'tree', 'sha');

        return $pulls instanceof Answer && $tree !== '' && array_any(
            $pulls->items(),
            fn(Answer $pull): bool => $pull->text('merged_at') !== ''
                && $this->isIntoDefaultBranch($pull)
                && $this->hasTree($pull->text('head', 'sha'), $tree)
                && $this->passed($pull->number('number'), $pull->text('head', 'sha'), $ledgers),
        );
    }

    /**
     * Whether a pull request is into this repository's default branch, as the repository's own API says: a merge
     * into another branch, which no branch protection may guard, proves nothing of the default branch.
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
    private function passed(int $pullRequest, string $head, ProofStore $ledgers): bool
    {
        return $pullRequest > 0 && $this->checks->passedAt($head) && $this->recordedAt($ledgers, $pullRequest, $head);
    }

    /**
     * Whether a pull request's own ledger records this head as passed under
     * the check, using none of its own proofs; not where it cannot be read.
     * Its proofs are never read (ADR-0049).
     */
    private function recordedAt(ProofStore $ledgers, int $pullRequest, string $head): bool
    {
        $ledger = $ledgers->read(Scope::pullRequest($pullRequest));
        $passed = $ledger instanceof Ledger ? $ledger->runs()->passed() : $ledger;

        return $passed instanceof Passed
            && $passed->commit()->name() === $head
            && $passed->check() === $this->check
            && ! $passed->usedOwnScope();
    }
}
