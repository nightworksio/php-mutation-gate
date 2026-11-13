<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function count;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\ProvingRange;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Config\MergedTrust;
use NightWorksIO\MutationGate\Core\Config\OwnScopeTrust;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ProofStore;

use function rawurlencode;
use function sprintf;

/**
 * GitHub's word on which commits of the default branch a pull request or a
 * merge group already proved. A commit is proved where it is the merge of a
 * pull request into the repository's default branch whose verdict passed on
 * its head, which is where the latest check-run GitHub Actions completed there
 * under the name `ci.check` concluded in success, and the pull request's own
 * ledger, read from its run's artifact, records the head as passed under that
 * check, and its tree is that head's, or under `ci.trustMergedPullRequests:
 * delta` any tree; and where a merge group's verdict passed on that very
 * commit (ADR-0049). A merge group's own head is proved where the same holds
 * of the pull request it was built for (ADR-0022). A pass that took results
 * from its own scope's proofs proves as `ci.mergedOwnScopeProofs` says, and
 * each pass carries its ledger's results but those it took from its own scope,
 * all of them under `trust` (ADR-0139). Without the ledgers to read, nothing
 * is proved.
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
        private ProvingRange $range,
        private MergedTrust $trust,
        private OwnScopeTrust $ownScope,
        private MergedAnswers $answers,
    ) {
    }

    /** The heads a repository merged, asked through its API, up to this head, under this check-run. */
    public static function of(Api $api, string $repository, string $head, string $check, ProvingRange $range): self
    {
        return new self(
            $api,
            $repository,
            $head,
            VerdictChecks::of($api, $repository, $check),
            $check,
            NoLedgers::none(),
            NotGiven::value(),
            $range,
            MergedTrust::Off,
            OwnScopeTrust::Refuse,
            MergedAnswers::none(),
        );
    }

    /** These heads, reading each pull request's own ledger from this store, and taking from it what these allow. */
    public function trusting(ProofStore $ledgers, MergedTrust $trust, OwnScopeTrust $ownScope): self
    {
        return new self(
            $this->api,
            $this->repository,
            $this->head,
            $this->checks,
            $this->check,
            $ledgers,
            $this->group,
            $this->range,
            $trust,
            $ownScope,
            MergedAnswers::none(),
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
            $this->range,
            $this->trust,
            $this->ownScope,
            $this->answers,
        );
    }

    /** The commit the run is at. */
    public function head(): Revision
    {
        return Revision::ref($this->head);
    }

    /** Whether every commit from a base to the head is proved, as {@see since()} says. */
    public function provedSince(Revision $base): bool
    {
        return $this->since($base)->isProved();
    }

    /**
     * For a merge group, what its pull request's pass proves of the group's head; for any other head, what the
     * passes of the pull requests and merge groups from a base to it prove. Nothing without the pull requests'
     * ledgers to read, and then GitHub is not asked. Asked once a base.
     */
    public function since(Revision $base): MergedRange
    {
        $ledgers = $this->ledgers;
        $group = $this->group;

        return match (true) {
            ! $ledgers instanceof ProofStore || ! $this->trust->trusts() => MergedRange::unproved(),
            $group instanceof PullRequestNumber => $this->answers->of(
                '',
                fn(): MergedRange => $this->groupProved($group, $ledgers),
            ),
            default => $this->answers->of($base->name(), fn(): MergedRange => $this->rangeProved($base, $ledgers)),
        };
    }

    /** What every commit from a base to the head proves. */
    private function rangeProved(Revision $base, ProofStore $ledgers): MergedRange
    {
        $comparison = $this->api->get(sprintf(
            '/repos/%s/compare/%s...%s',
            $this->repository,
            rawurlencode($base->name()),
            rawurlencode($this->head),
        ));

        if ($comparison instanceof CannotTell) {
            return MergedRange::unproved();
        }

        $commits = $comparison->items('commits');

        return count($commits) <= $this->range->farthest()
            && count($commits) === $comparison->number('total_commits')
            ? $this->provedAll($commits, $ledgers)
            : MergedRange::unproved();
    }

    /**
     * What a merge group's head is proved by: the pass of the pull request it was built for, into the default branch.
     */
    private function groupProved(PullRequestNumber $pullRequest, ProofStore $ledgers): MergedRange
    {
        $pull = $this->api->get(sprintf('/repos/%s/pulls/%d', $this->repository, $pullRequest->value()));
        $group = $this->api->get(sprintf('/repos/%s/git/commits/%s', $this->repository, rawurlencode($this->head)));

        if (! $pull instanceof Answer || ! $group instanceof Answer || ! $this->isIntoDefaultBranch($pull)) {
            return MergedRange::unproved();
        }

        $proved = $this->pulledProved($pull, $pullRequest->value(), $group->text('tree', 'sha'), $ledgers);

        return $proved instanceof MergedRange ? $proved : MergedRange::unproved();
    }

    /**
     * What these commits prove together, oldest first: nothing unless every one is proved.
     *
     * @param list<Answer> $commits
     */
    private function provedAll(array $commits, ProofStore $ledgers): MergedRange
    {
        $proved = MergedRange::proved();

        foreach ($commits as $commit) {
            $one = $this->proves($commit, $ledgers);

            if (! $one->isProved()) {
                return MergedRange::unproved();
            }

            $proved = $proved->and($one->carried(), $one->from());
        }

        return $proved;
    }

    /**
     * What a commit proves: the pass of a pull request merged into the default branch whose head it lands, or a
     * merge group's pass on it.
     */
    private function proves(Answer $commit, ProofStore $ledgers): MergedRange
    {
        $merged = $this->merged($commit, $ledgers);

        return match (true) {
            $merged instanceof MergedRange => $merged,
            $this->checks->passedInQueueAt($commit->text('sha')) => MergedRange::proved(),
            default => MergedRange::unproved(),
        };
    }

    /** What the first pull request merged into the default branch that a commit lands, and that passed, proves. */
    private function merged(Answer $commit, ProofStore $ledgers): MergedRange|NotGiven
    {
        $pulls = $this->api->get(sprintf('/repos/%s/commits/%s/pulls', $this->repository, $commit->text('sha')));
        $tree = $commit->text('commit', 'tree', 'sha');

        if (! $pulls instanceof Answer || $tree === '') {
            return NotGiven::value();
        }

        foreach ($pulls->items() as $pull) {
            $proved = $pull->text('merged_at') !== '' && $this->isIntoDefaultBranch($pull)
                ? $this->pulledProved($pull, $pull->number('number'), $tree, $ledgers)
                : NotGiven::value();

            if ($proved instanceof MergedRange) {
                return $proved;
            }
        }

        return NotGiven::value();
    }

    /**
     * What a pull request's pass at its head proves of a landed tree: the tree where it is the head's, or under
     * `delta` the change from the head to it; nothing where it did not pass there.
     */
    private function pulledProved(Answer $pull, int $number, string $tree, ProofStore $ledgers): MergedRange|NotGiven
    {
        $head = $pull->text('head', 'sha');
        $carried = $tree !== '' ? $this->passed($number, $head, $ledgers) : NotGiven::value();

        return match (true) {
            ! $carried instanceof Proofs => NotGiven::value(),
            $this->hasTree($head, $tree) => MergedRange::proved()->and($carried, NotGiven::value()),
            $this->trust === MergedTrust::Delta => MergedRange::proved()->and($carried, Revision::ref($head)),
            default => NotGiven::value(),
        };
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
     * The results a pull request's verdict that passed at this head carries: its check-run concluded in success
     * there, and its own ledger records the head as passed under that check, as `ci.mergedOwnScopeProofs` allows.
     */
    private function passed(int $pullRequest, string $head, ProofStore $ledgers): Proofs|NotGiven
    {
        return $pullRequest > 0 && $this->checks->passedAt($head)
            ? $this->recordedAt($ledgers, $pullRequest, $head)
            : NotGiven::value();
    }

    /**
     * The results a pull request's own ledger carries where it records this head as passed under the check, and
     * its pass is one `ci.mergedOwnScopeProofs` takes: under `refuse`, one that used nothing of its own scope; under
     * `reach`, one whose map was not measured on its own scope and that lists the units it took from it, which are
     * left out; under `trust`, any. Nothing where it cannot be read (ADR-0049, ADR-0139).
     */
    private function recordedAt(ProofStore $ledgers, int $pullRequest, string $head): Proofs|NotGiven
    {
        $ledger = $ledgers->read(Scope::pullRequest($pullRequest));
        $passed = $ledger instanceof Ledger ? $ledger->runs()->passed() : $ledger;

        return $ledger instanceof Ledger
            && $passed instanceof Passed
            && $passed->commit()->name() === $head
            && $passed->check() === $this->check
            ? $passed->trusted($this->ownScope, $ledger->proofs())
            : NotGiven::value();
    }
}
