<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\ProvingRange;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Config\Reuse;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\ReusablePass;
use NightWorksIO\MutationGate\Port\ProofStore;

use function rawurlencode;
use function sprintf;

/**
 * GitHub's word on what a pull request or a merge group already judged of the
 * default branch. A pull request counts where it was merged into the
 * repository's default branch from the repository itself, or from a fork
 * under `reuse.forks`, and its verdict passed on its head, which is where the
 * latest check-run GitHub Actions completed there under the name `ci.check`
 * concluded in success, and its own ledger, read from its run's artifact,
 * records the head as passed under that check. Its pass lends the default
 * branch its ledger's results, which prove a unit only where their key
 * matches (ADR-0198); under `reuse.delta` it proves its commit instead,
 * reaching only the change from its head where the landed tree differs; and
 * a merge group's verdict that passed on a commit proves it (ADR-0049). A
 * merge group's own head takes the same from the pull request it was built
 * for (ADR-0022). A pass that took results from its own scope's proofs is
 * taken as `reuse.ownScopeProofs` says, and carries its ledger's results but
 * those it took from its own scope, all of them under `trust` (ADR-0139). The
 * pass of the pull request a head lands, or a merge group was built for, is
 * one the run may reuse whole. Without the ledgers to read, nothing counts.
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
        private PassedPulls $pulls,
        private ProofStore|NoLedgers $ledgers,
        private PullRequestNumber|NotGiven $group,
        private ProvingRange $range,
        private Reuse $reuse,
        private MergedAnswers $answers,
    ) {
    }

    /** The heads a repository merged, asked through its API, up to this head, under this check-run. */
    public static function of(Api $api, string $repository, string $head, string $check, ProvingRange $range): self
    {
        $checks = VerdictChecks::of($api, $repository, $check);

        return new self(
            $api,
            $repository,
            $head,
            $checks,
            PassedPulls::of($repository, $checks, $check, Reuse::of()),
            NoLedgers::none(),
            NotGiven::value(),
            $range,
            Reuse::of(),
            MergedAnswers::none(),
        );
    }

    /** These heads, reading each pull request's own ledger from this store, and taking from it what `reuse` allows. */
    public function trusting(ProofStore $ledgers, Reuse $reuse): self
    {
        return new self(
            $this->api,
            $this->repository,
            $this->head,
            $this->checks,
            $this->pulls->under($reuse),
            $ledgers,
            $this->group,
            $this->range,
            $reuse,
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
            $this->pulls,
            $this->ledgers,
            $pullRequest,
            $this->range,
            $this->reuse,
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
     * ledgers to read, or where `reuse.identical` reuses nothing there, and then GitHub is not asked. Asked once a
     * base.
     */
    public function since(Revision $base): MergedRange
    {
        $ledgers = $this->ledgers;
        $group = $this->group;

        return match (true) {
            ! $ledgers instanceof ProofStore || ! $this->reuses() => MergedRange::unproved(),
            $group instanceof PullRequestNumber => $this->answers->of(
                '',
                fn(): MergedRange => $this->groupProved($group, $ledgers),
            ),
            default => $this->answers->of($base->name(), fn(): MergedRange => $this->rangeProved($base, $ledgers)),
        };
    }

    /**
     * The pass of the pull request a merge group was built for, or that the head lands, a run may reuse whole: one
     * `reuse.ownScopeProofs` takes whole, which `trust` does, and otherwise one that used nothing of its own scope.
     * None without the ledgers to read, or where `reuse.identical` reuses nothing there (ADR-0198).
     *
     * @return list<ReusablePass>
     */
    public function reusable(): array
    {
        $ledgers = $this->ledgers;
        $group = $this->group;

        return $ledgers instanceof ProofStore && $this->reuses()
            ? $this->answers->reusable(fn(): array => $this->pulls->firstReusable(
                $ledgers,
                $group instanceof PullRequestNumber
                    ? [$this->api->get(sprintf('/repos/%s/pulls/%d', $this->repository, $group->value()))]
                    : $this->pullsOf($this->head),
            ))
            : [];
    }

    /** Whether `reuse.identical` reuses anything for this head: a merge group's, or the default branch's. */
    private function reuses(): bool
    {
        return $this->reuse->identical()->reusesOn(defaultBranch: ! $this->group instanceof PullRequestNumber);
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

        if (! $pull instanceof Answer || ! $group instanceof Answer || ! $this->pulls->counts($pull)) {
            return MergedRange::unproved();
        }

        $proved = $this->pulledProved($pull, $pullRequest->value(), $group->text('tree', 'sha'), $ledgers);

        return $proved instanceof MergedRange ? $proved : MergedRange::unproved();
    }

    /**
     * What these commits prove together, oldest first: proved where every one is, and lending what each lends.
     *
     * @param list<Answer> $commits
     */
    private function provedAll(array $commits, ProofStore $ledgers): MergedRange
    {
        $proved = MergedRange::proved();

        foreach ($commits as $commit) {
            $proved = $proved->then($this->proves($commit, $ledgers));
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
            $proved = $pull->text('merged_at') !== '' && $this->pulls->counts($pull)
                ? $this->pulledProved($pull, $pull->number('number'), $tree, $ledgers)
                : NotGiven::value();

            if ($proved instanceof MergedRange) {
                return $proved;
            }
        }

        return NotGiven::value();
    }

    /**
     * What a pull request's pass at its head gives a landed tree: its results, lent; under `delta`, the tree proved
     * where it is the head's, and otherwise the change from the head to it; nothing where it did not pass there.
     */
    private function pulledProved(Answer $pull, int $number, string $tree, ProofStore $ledgers): MergedRange|NotGiven
    {
        $head = $pull->text('head', 'sha');
        $carried = $tree !== '' ? $this->pulls->carried($ledgers, $number, $head) : NotGiven::value();

        return match (true) {
            ! $carried instanceof Proofs => NotGiven::value(),
            ! $this->reuse->delta() => MergedRange::lending($carried),
            $this->hasTree($head, $tree) => MergedRange::proved()->and($carried, NotGiven::value()),
            default => MergedRange::proved()->and($carried, Revision::ref($head)),
        };
    }

    /** Whether a commit is of this tree. */
    private function hasTree(string $commit, string $tree): bool
    {
        $answer = $this->api->get(sprintf('/repos/%s/git/commits/%s', $this->repository, $commit));

        return $answer instanceof Answer && $answer->text('tree', 'sha') === $tree;
    }

    /** @return list<Answer> the pull requests merged into the default branch that a commit lands */
    private function pullsOf(string $commit): array
    {
        $pulls = $this->api->get(sprintf('/repos/%s/commits/%s/pulls', $this->repository, $commit));

        return $pulls instanceof Answer
            ? array_values(array_filter(
                $pulls->items(),
                static fn(Answer $pull): bool => $pull->text('merged_at') !== '',
            ))
            : [];
    }
}
