<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_any;
use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\ProvingRange;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;

use function rawurlencode;
use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Another change source, and GitHub's word on what the default branch already
 * proved. When every commit since the base is the tree of a merged pull
 * request's head, and that pull request's verdict passed on that head, the
 * branch was up to date and its run judged exactly the tree that landed, so
 * those commits reach nothing and only what is uncommitted counts.
 *
 * A pull request's verdict passed where the check-run `ci.check` names
 * concluded in success on its head, and its own ledger records that head as
 * passed under that check with no proof of its own scope used, since its own
 * code could have written those. Without the ledgers to read, nothing is
 * proved. A range of more commits than the proving range holds, or one commit
 * it cannot prove, is read from the other source whole. Where the checkout
 * stands, the other source says.
 */
final readonly class PassedPullRequests implements ChangeSource, Repository
{
    /** Where GitHub's API is, unless `GITHUB_API_URL` says otherwise. */
    private const string API = 'https://api.github.com';

    private function __construct(
        private ChangeSource&Repository $source,
        private Api $api,
        private string $repository,
        private string $head,
        private string $check,
        private ProofStore|NoLedgers $ledgers,
    ) {
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

        return $repository === '' || $head === '' || $check === ''
            ? $source
            : new self(
                $source,
                Api::at($client, $api === '' ? self::API : $api, $read('GITHUB_TOKEN')),
                $repository,
                $head,
                $check,
                NoLedgers::none(),
            );
    }

    /** This source, reading each pull request's own ledger from this store. */
    public function trusting(ProofStore $ledgers): self
    {
        return new self($this->source, $this->api, $this->repository, $this->head, $this->check, $ledgers);
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        return $this->source->changesSince($this->provedSince($base) ? Revision::ref($this->head) : $base);
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

    public function branch(): Scope|Detached|CannotTell
    {
        return $this->source->branch();
    }

    public function defaultBranch(): Scope|CannotTell
    {
        return $this->source->defaultBranch();
    }

    /** Whether every commit from a base to the head is the tree of a pull request whose run passed. */
    private function provedSince(Revision $base): bool
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
     * the check, using none of its own proofs.
     */
    private function recordedAt(int $pullRequest, string $head): bool
    {
        $passed = $this->ledgers instanceof ProofStore
            ? $this->ledgers->read(Scope::pullRequest($pullRequest))->lastPassed()
            : NoLedgers::none();

        return $passed instanceof Passed
            && $passed->commit()->name() === $head
            && $passed->check() === $this->check
            && ! $passed->usedOwnScope();
    }
}
