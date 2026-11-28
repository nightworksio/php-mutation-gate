<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Config\OwnScopeTrust;
use NightWorksIO\MutationGate\Core\Config\Reuse;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\ReusablePass;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * What the default branch takes from a pull request's own pass, as `reuse`
 * allows (ADR-0139, ADR-0198). A pull request counts where it is into the
 * repository's default branch, as the repository's own API says, and from
 * the repository itself, or from a fork under `reuse.forks`; its pass counts
 * where its check-run concluded in success at its head and its own ledger,
 * read from its run's artifact, records that head as passed under the check.
 * Such a pass carries its ledger's results as `reuse.ownScopeProofs` takes
 * them, and a run may reuse it whole where it took nothing of its own scope,
 * or under `trust`.
 *
 * @internal
 */
final readonly class PassedPulls
{
    private function __construct(
        private string $repository,
        private VerdictChecks $checks,
        private string $check,
        private Reuse $reuse,
    ) {
    }

    /** The passes of this repository's pull requests, under this check-run, taken as `reuse` allows. */
    public static function of(string $repository, VerdictChecks $checks, string $check, Reuse $reuse): self
    {
        return new self($repository, $checks, $check, $reuse);
    }

    /** These passes, taken as this `reuse` allows. */
    public function under(Reuse $reuse): self
    {
        return new self($this->repository, $this->checks, $this->check, $reuse);
    }

    /**
     * Whether a pull request counts: into this repository's default branch, which branch protection may guard, and
     * from the repository itself, or from a fork under `reuse.forks`, since a fork's run wrote what its author chose.
     */
    public function counts(Answer $pull): bool
    {
        $branch = $pull->text('base', 'ref');

        return $branch !== ''
            && $branch === $pull->text('base', 'repo', 'default_branch')
            && $pull->text('base', 'repo', 'full_name') === $this->repository
            && ($this->reuse->forks() || $pull->text('head', 'repo', 'full_name') === $this->repository);
    }

    /**
     * The results a pull request's pass at this head carries, as `reuse.ownScopeProofs` takes them: under
     * `refuse`, all where it used nothing of its own scope; under `reach`, all but the units it took from it, where
     * its map was not measured on it and it lists them; under `trust`, all (ADR-0049, ADR-0139).
     */
    public function carried(ProofStore $ledgers, int $pullRequest, string $head): Proofs|NotGiven
    {
        $ledger = $this->ledgerAt($ledgers, $pullRequest, $head);
        $passed = $ledger instanceof Ledger ? $ledger->runs()->passed() : $ledger;

        return $ledger instanceof Ledger && $passed instanceof Passed
            ? $passed->trusted($this->reuse->ownScopeProofs(), $ledger->proofs())
            : NotGiven::value();
    }

    /** The pass of a pull request that counts, which a run may reuse whole; none where there is none. */
    public function reusable(ProofStore $ledgers, Answer $pull): ReusablePass|NotGiven
    {
        $number = PullRequestNumber::of($pull->number('number'));

        return $number instanceof PullRequestNumber && $this->counts($pull)
            ? $this->reusableAt($ledgers, $number, $pull->text('head', 'sha'))
            : NotGiven::value();
    }

    /**
     * The pass of the first of these pull requests a run may reuse whole; none where none has one.
     *
     * @param  list<Answer|CannotTell> $pulls
     * @return list<ReusablePass>
     */
    public function firstReusable(ProofStore $ledgers, array $pulls): array
    {
        foreach ($pulls as $pull) {
            $pass = $pull instanceof Answer ? $this->reusable($ledgers, $pull) : NotGiven::value();

            if ($pass instanceof ReusablePass) {
                return [$pass];
            }
        }

        return [];
    }

    /** The pass of this pull request at this head, which a run may reuse whole; none where there is none. */
    private function reusableAt(ProofStore $ledgers, PullRequestNumber $number, string $head): ReusablePass|NotGiven
    {
        $ledger = $this->ledgerAt($ledgers, $number->value(), $head);
        $passed = $ledger instanceof Ledger ? $ledger->runs()->passed() : $ledger;

        return $passed instanceof Passed
            && ($this->reuse->ownScopeProofs() === OwnScopeTrust::Trust || ! $passed->usedOwnScope())
            ? ReusablePass::ofPullRequest($passed, $number)
            : NotGiven::value();
    }

    /**
     * A pull request's own ledger, where its check-run concluded in success at this head and the ledger records the
     * head as passed under that check; nothing where it cannot be read.
     */
    private function ledgerAt(ProofStore $ledgers, int $pullRequest, string $head): Ledger|NotGiven
    {
        $ledger = $pullRequest > 0 && $this->checks->passedAt($head)
            ? $ledgers->read(Scope::pullRequest($pullRequest))
            : NotGiven::value();
        $passed = $ledger instanceof Ledger ? $ledger->runs()->passed() : $ledger;

        return $ledger instanceof Ledger
            && $passed instanceof Passed
            && $passed->commit()->name() === $head
            && $passed->check() === $this->check
            ? $ledger
            : NotGiven::value();
    }
}
