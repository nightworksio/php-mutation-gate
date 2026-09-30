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
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\Repository;

use function preg_match;
use function rawurlencode;
use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Another change source, and GitHub's word on what the default branch already
 * proved. When every commit since the base is the tree of a merged pull
 * request's head, and that pull request's run of this workflow passed, the
 * branch was up to date and its run judged exactly the tree that landed, so
 * those commits reach nothing and only what is uncommitted counts. A range of
 * more commits than the proving range holds, or one commit it cannot prove,
 * is read from the other source whole. Where the checkout stands, the other
 * source says.
 */
final readonly class PassedPullRequests implements ChangeSource, Repository
{
    /** Where GitHub's API is, unless `GITHUB_API_URL` says otherwise. */
    private const string API = 'https://api.github.com';

    /** How `GITHUB_WORKFLOW_REF` spells the workflow: owner, repository, then its path before the ref. */
    private const string WORKFLOW = '~^[^/]+/[^/]+/(?<path>[^@]+)@~';

    private function __construct(
        private ChangeSource&Repository $source,
        private Api $api,
        private string $repository,
        private string $head,
        private string $workflow,
    ) {
    }

    /**
     * Another change source, with GitHub's word taken where these environment
     * variables name the run: `GITHUB_REPOSITORY`, `GITHUB_SHA` and
     * `GITHUB_WORKFLOW_REF`, asked with `GITHUB_TOKEN` at `GITHUB_API_URL`.
     * Where they name none, the other source alone.
     *
     * @param array<string, string> $environment
     */
    public static function over(
        ChangeSource&Repository $source,
        HttpClientInterface $client,
        array $environment,
    ): ChangeSource&Repository {
        $read = static fn(string $name): string => array_key_exists($name, $environment) ? $environment[$name] : '';
        $repository = $read('GITHUB_REPOSITORY');
        $head = $read('GITHUB_SHA');
        $api = $read('GITHUB_API_URL');
        $workflow = preg_match(self::WORKFLOW, $read('GITHUB_WORKFLOW_REF'), $found) === 1 ? $found['path'] : '';

        return $repository === '' || $head === '' || $workflow === ''
            ? $source
            : new self(
                $source,
                Api::at($client, $api === '' ? self::API : $api, $read('GITHUB_TOKEN')),
                $repository,
                $head,
                $workflow,
            );
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        return $this->source->changesSince($this->provedSince($base) ? Revision::ref($this->head) : $base);
    }

    public function fingerprints(): Fingerprints|CannotTell
    {
        return $this->source->fingerprints();
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

    /** Whether a commit's tree is the head of a merged pull request whose run of this workflow passed. */
    private function proves(Answer $commit): bool
    {
        $pulls = $this->api->get(sprintf('/repos/%s/commits/%s/pulls', $this->repository, $commit->text('sha')));
        $tree = $commit->text('commit', 'tree', 'sha');

        return $pulls instanceof Answer && $tree !== '' && array_any(
            $pulls->items(),
            fn(Answer $pull): bool => $pull->text('merged_at') !== ''
                && $this->hasTree($pull->text('head', 'sha'), $tree)
                && $this->passed($pull->text('head', 'sha')),
        );
    }

    /** Whether a commit is of this tree. */
    private function hasTree(string $commit, string $tree): bool
    {
        $answer = $this->api->get(sprintf('/repos/%s/git/commits/%s', $this->repository, $commit));

        return $answer instanceof Answer && $answer->text('tree', 'sha') === $tree;
    }

    /** Whether a run of this workflow passed for a pull request at this head. */
    private function passed(string $head): bool
    {
        $runs = $this->api->get(sprintf(
            '/repos/%s/actions/runs?head_sha=%s&event=pull_request&status=success',
            $this->repository,
            $head,
        ));

        return $runs instanceof Answer && array_any(
            $runs->items('workflow_runs'),
            fn(Answer $run): bool => $run->text('path') === $this->workflow && $run->text('conclusion') === 'success',
        );
    }
}
