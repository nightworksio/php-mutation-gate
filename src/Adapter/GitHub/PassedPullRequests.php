<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Looked;
use NightWorksIO\MutationGate\Core\Change\ProvingRange;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Config\MergedTrust;
use NightWorksIO\MutationGate\Core\Config\OwnScopeTrust;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Another change source, and GitHub's word on what the default branch already
 * proved ({@see MergedHeads}). When every commit since the base is the tree
 * of a merged pull request's head, and that pull request's verdict passed on
 * that head, the branch was up to date and its run judged exactly the tree
 * that landed, so those commits reach nothing and only what is uncommitted
 * counts; and so where a commit is a merge group's whose verdict passed on
 * it. Under `delta`, a landed tree that differs reaches what changed from
 * the head the pull request's verdict judged (ADR-0139). On a merge group, `GITHUB_REF` names the pull request it was built
 * for, and the group reaches nothing where its tree is that pull request's
 * head, whose verdict passed there (ADR-0022, ADR-0049). A range of more
 * commits than the proving range holds, or one commit it cannot prove, is
 * read from the other source whole. Where the checkout stands, and what
 * changed from a commit itself, the other source says.
 *
 * @internal
 */
final readonly class PassedPullRequests implements ChangeSource, Repository
{
    private function __construct(private ChangeSource&Repository $source, private MergedHeads $heads)
    {
    }

    /**
     * Another change source, with GitHub's word taken where these environment
     * variables name the run: `GITHUB_REPOSITORY` and `GITHUB_SHA`, asked
     * with `GITHUB_TOKEN` at `GITHUB_API_URL`, and the verdict's check-run
     * is named so. Where they name none, the other source alone.
     *
     * @param array<string, string> $environment
     */
    public static function over(
        ChangeSource&Repository $source,
        HttpClientInterface $client,
        array $environment,
        string $check,
        int $range = ProvingRange::STANDARD,
    ): ChangeSource&Repository {
        $read = static fn(string $name): string => array_key_exists($name, $environment) ? $environment[$name] : '';
        $repository = $read('GITHUB_REPOSITORY');
        $head = $read('GITHUB_SHA');

        if ($repository === '' || $head === '' || $check === '') {
            return $source;
        }

        $heads = MergedHeads::of(
            Api::at($client, $read('GITHUB_API_URL'), $read('GITHUB_TOKEN')),
            $repository,
            $head,
            $check,
            ProvingRange::of($range),
        );
        $queued = $read('GITHUB_EVENT_NAME') === GitHubPlan::MERGE_GROUP
            ? QueueBranch::parse($read('GITHUB_REF'))
            : NotGiven::value();

        return new self($source, $queued instanceof QueueBranch ? $heads->inGroupFor($queued->pullRequest()) : $heads);
    }

    /** This source, reading each pull request's own ledger from this store, and taking from it what these allow. */
    public function trusting(ProofStore $ledgers, MergedTrust $trust, OwnScopeTrust $ownScope): self
    {
        return new self($this->source, $this->heads->trusting($ledgers, $trust, $ownScope));
    }

    /**
     * The change since a base: none of the commits proved, where all are; from the judged head of the oldest pull
     * request whose landed tree differs, under `delta`, or since the base where that head cannot be read.
     */
    public function changesSince(Revision $base): Changes|CannotTell
    {
        $merged = $this->heads->since($base);
        $from = $merged->from();

        if (! $merged->isProved()) {
            return $this->source->changesSince($base);
        }

        $changes = $from instanceof Revision
            ? $this->source->changesFrom($from)
            : $this->source->changesSince($this->heads->head());

        return $changes instanceof CannotTell ? $this->source->changesSince($base) : $changes;
    }

    /**
     * The results the pull requests merged since the default branch's last pass carry, where every commit since
     * is proved; for a merge group, those of its pull request.
     */
    public function carriedOnto(Ledger $defaultBranch): Proofs
    {
        $passed = $defaultBranch->runs()->passed();

        return $this->heads->since($passed instanceof Passed ? $passed->commit() : $this->heads->head())->carried();
    }

    public function changesFrom(Revision $commit): Changes|CannotTell
    {
        return $this->source->changesFrom($commit);
    }

    public function judged(Revision $commit): JudgedCommit|CannotTell
    {
        return $this->source->judged($commit);
    }

    public function readable(JudgedCommit $judged): Revision|CannotTell
    {
        return $this->source->readable($judged);
    }

    public function fingerprints(): Fingerprints|CannotTell
    {
        return $this->source->fingerprints();
    }

    public function lookedSince(Looked $then): Looked|CannotTell
    {
        return $this->source->lookedSince($then);
    }

    public function listed(Paths $paths): Paths|CannotTell
    {
        return $this->source->listed($paths);
    }

    public function unstaged(): Paths|CannotTell
    {
        return $this->source->unstaged();
    }

    /** @return ByPath<Instant>|CannotTell */
    public function lastChanged(Paths $paths): ByPath|CannotTell
    {
        return $this->source->lastChanged($paths);
    }

    public function fileAt(Path $path, Revision $revision): Contents|Missing|CannotTell
    {
        return $this->source->fileAt($path, $revision);
    }

    /** @return ByPath<Contents|Missing>|CannotTell */
    public function filesAt(Paths $paths, Revision $revision): ByPath|CannotTell
    {
        return $this->source->filesAt($paths, $revision);
    }

    public function head(): Revision|CannotTell
    {
        return $this->source->head();
    }

    public function isClean(): bool|CannotTell
    {
        return $this->source->isClean();
    }

    public function branch(): Scope|Detached|CannotTell
    {
        return $this->source->branch();
    }

    public function defaultBranch(): Scope|CannotTell
    {
        return $this->source->defaultBranch();
    }
}
