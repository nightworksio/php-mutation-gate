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
 * already proved: a commit is proved where its tree is the head of a merged
 * pull request whose verdict passed on that head, which is where the check-run
 * `ci.check` names concluded in success there, and the pull request's own
 * ledger records the head as passed under that check with no proof of its
 * own scope used. Without the ledgers to read, nothing is proved.
 */
final readonly class MergedHeads
{
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

    /** Whether a commit's tree is the head of a merged pull request whose verdict passed on it. */
    private function proves(Answer $commit): bool
    {
        $pulls = $this->api->get(sprintf('/repos/%s/commits/%s/pulls', $this->repository, $commit->text('sha')));
        $tree = $commit->text('commit', 'tree', 'sha');

        return $pulls instanceof Answer && $tree !== '' && array_any(
            $pulls->items(),
            fn(Answer $pull): bool => $pull->text('merged_at') !== ''
                && $this->hasTree($pull->text('head', 'sha'), $tree)
                && $this->passed($pull->number('number'), $pull->text('head', 'sha')),
        );
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

    /** Whether the check-run `ci.check` names concluded in success on this head. */
    private function checkedAt(string $head): bool
    {
        $runs = $this->api->get(sprintf(
            '/repos/%s/commits/%s/check-runs?check_name=%s&status=completed',
            $this->repository,
            $head,
            rawurlencode($this->check),
        ));

        return $runs instanceof Answer && array_any(
            $runs->items('check_runs'),
            fn(Answer $run): bool => $run->text('name') === $this->check && $run->text('conclusion') === 'success',
        );
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
