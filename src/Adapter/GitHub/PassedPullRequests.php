<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
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
 * counts. A range of more commits than the proving range holds, or one commit
 * it cannot prove, is read from the other source whole. Where the checkout
 * stands, and what changed from a commit itself, the other source says.
 */
final readonly class PassedPullRequests implements ChangeSource, Repository
{
    /** Where GitHub's API is, unless `GITHUB_API_URL` says otherwise. */
    private const string API = 'https://api.github.com';

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
    ): ChangeSource&Repository {
        $read = static fn(string $name): string => array_key_exists($name, $environment) ? $environment[$name] : '';
        $repository = $read('GITHUB_REPOSITORY');
        $head = $read('GITHUB_SHA');
        $api = $read('GITHUB_API_URL');

        if ($repository === '' || $head === '' || $check === '') {
            return $source;
        }

        $heads = MergedHeads::of(
            Api::at($client, $api === '' ? self::API : $api, $read('GITHUB_TOKEN')),
            $repository,
            $head,
            $check,
        );

        return new self($source, $heads);
    }

    /** This source, reading each pull request's own ledger from this store. */
    public function trusting(ProofStore $ledgers): self
    {
        return new self($this->source, $this->heads->trusting($ledgers));
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        return $this->source->changesSince($this->heads->provedSince($base) ? $this->heads->head() : $base);
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
