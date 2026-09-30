<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\PassedPullRequests;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

// The run is a push of `head` to the default branch, whose last passing commit
// is `base`. The source underneath answers what is uncommitted when asked from
// `head`, and cannot tell when asked from anywhere else.

const PULL_REQUESTS_API = 'https://api.github.com/repos/octo/gate';

const PULL_REQUESTS_RUN = [
    'GITHUB_REPOSITORY' => 'octo/gate',
    'GITHUB_SHA' => 'head',
    'GITHUB_WORKFLOW_REF' => 'octo/gate/.github/workflows/gate.yml@refs/heads/main',
    'GITHUB_TOKEN' => 'secret',
];

$uncommitted = static fn(): Changes => Changes::of(Change::modified(Path::of('src/Money.php'), Lines::none()));

$source = static fn(): ChangeSource => new ChangeSourceFake(Revision::ref('head'), Changes::of(Change::modified(Path::of('src/Money.php'), Lines::none())), [
    'base' => ['src/Money.php' => 'base'],
    Revision::workingTree()->name() => ['src/Money.php' => 'disk'],
]);

/**
 * GitHub, answering these paths of the repository's API with these bodies,
 * and every other with a 404.
 *
 * @param array<string, mixed> $answers
 */
function answering(array $answers): MockHttpClient
{
    return new MockHttpClient(static fn(string $method, string $url): ResponseInterface => array_key_exists($url, $answers)
        ? new JsonMockResponse($answers[$url])
        : new MockResponse('{"message": "Not Found"}', ['http_code' => 404]));
}

/**
 * What GitHub says of commits each of which is the tree of a merged pull
 * request whose run of the workflow passed.
 *
 * @param  list<string>         $commits
 * @return array<string, mixed>
 */
function provedCommits(array $commits, int $total = -1): array
{
    $answers = [sprintf('%s/compare/base...head', PULL_REQUESTS_API) => [
        'total_commits' => $total === -1 ? count($commits) : $total,
        'commits' => array_map(static fn(string $commit): array => ['sha' => $commit, 'commit' => ['tree' => ['sha' => sprintf('tree-%s', $commit)]]], $commits),
    ]];

    foreach ($commits as $commit) {
        $answers[sprintf('%s/commits/%s/pulls', PULL_REQUESTS_API, $commit)] = [['merged_at' => '2026-09-30T10:00:00Z', 'head' => ['sha' => sprintf('pr-%s', $commit)]]];
        $answers[sprintf('%s/git/commits/pr-%s', PULL_REQUESTS_API, $commit)] = ['tree' => ['sha' => sprintf('tree-%s', $commit)]];
        $answers[sprintf('%s/actions/runs?head_sha=pr-%s&event=pull_request&status=success', PULL_REQUESTS_API, $commit)] = [
            'workflow_runs' => [
                ['path' => '.github/workflows/lint.yml', 'conclusion' => 'failure'],
                ['path' => '.github/workflows/gate.yml', 'conclusion' => 'success'],
            ],
        ];
    }

    return $answers;
}

it('reads only what is uncommitted when every commit since the base is the tree of a pull request whose run passed', function () use ($source, $uncommitted): void {
    $proved = PassedPullRequests::over($source(), answering(provedCommits(['one', 'two'])), PULL_REQUESTS_RUN);

    expect($proved->changesSince(Revision::ref('base')))->toEqual($uncommitted());
});

it('reads only what is uncommitted when the base is the head', function () use ($source, $uncommitted): void {
    $proved = PassedPullRequests::over($source(), answering(provedCommits([])), PULL_REQUESTS_RUN);

    expect($proved->changesSince(Revision::ref('base')))->toEqual($uncommitted());
});

it('asks about twenty commits, and no more', function (int $commits, bool $proved) use ($source): void {
    $names = array_map(static fn(int $commit): string => sprintf('c%d', $commit), range(1, $commits));
    $changes = PassedPullRequests::over($source(), answering(provedCommits($names)), PULL_REQUESTS_RUN)->changesSince(Revision::ref('base'));

    expect($changes instanceof Changes)->toBe($proved);
})->with([
    'twenty' => [20, true],
    'twenty-one' => [21, false],
]);

/**
 * What GitHub says of the commits `one` and `two`, spoilt in one way that
 * leaves a commit unproved.
 *
 * @param  array<string, mixed> $answers
 * @return array<string, mixed>
 */
function spoilt(string $how, array $answers): array
{
    return match ($how) {
        'GitHub cannot compare' => array_diff_key($answers, [sprintf('%s/compare/base...head', PULL_REQUESTS_API) => true]),
        'the count does not add up' => [...$answers, ...provedCommits(['one', 'two'], 3)],
        'no pull request is known' => array_diff_key($answers, [sprintf('%s/commits/two/pulls', PULL_REQUESTS_API) => true]),
        'the pull request was not merged' => [...$answers, sprintf('%s/commits/two/pulls', PULL_REQUESTS_API) => [['merged_at' => null, 'head' => ['sha' => 'pr-two']]]],
        'the pull request says nothing of a merge' => [...$answers, sprintf('%s/commits/two/pulls', PULL_REQUESTS_API) => [['head' => ['sha' => 'pr-two']]]],
        'its head is another tree' => [...$answers, sprintf('%s/git/commits/pr-two', PULL_REQUESTS_API) => ['tree' => ['sha' => 'tree-other']]],
        'its head cannot be read' => array_diff_key($answers, [sprintf('%s/git/commits/pr-two', PULL_REQUESTS_API) => true]),
        'no run is known' => array_diff_key($answers, [sprintf('%s/actions/runs?head_sha=pr-two&event=pull_request&status=success', PULL_REQUESTS_API) => true]),
        'no run of this workflow passed' => [...$answers, sprintf('%s/actions/runs?head_sha=pr-two&event=pull_request&status=success', PULL_REQUESTS_API) => [
            'workflow_runs' => [['path' => '.github/workflows/lint.yml', 'conclusion' => 'success'], ['path' => '.github/workflows/gate.yml', 'conclusion' => 'failure']],
        ]],
        'the commit names no tree' => [
            ...$answers,
            sprintf('%s/compare/base...head', PULL_REQUESTS_API) => ['total_commits' => 1, 'commits' => [['sha' => 'one', 'commit' => []]]],
            sprintf('%s/git/commits/pr-one', PULL_REQUESTS_API) => ['tree' => []],
        ],
        default => $answers,
    };
}

it('reads everything since the base when a commit cannot be proved', function (string $how) use ($source): void {
    $changes = PassedPullRequests::over($source(), answering(spoilt($how, provedCommits(['one', 'two']))), PULL_REQUESTS_RUN)
        ->changesSince(Revision::ref('base'));

    expect($changes)->toEqual(CannotTell::because('base is not a revision this repository has.'));
})->with([
    'GitHub cannot compare',
    'the count does not add up',
    'no pull request is known',
    'the pull request was not merged',
    'the pull request says nothing of a merge',
    'its head is another tree',
    'its head cannot be read',
    'no run is known',
    'no run of this workflow passed',
    'the commit names no tree',
]);

it('asks GitHub where GITHUB_API_URL says, with GITHUB_TOKEN', function () use ($source, $uncommitted): void {
    $response = new JsonMockResponse(['total_commits' => 0, 'commits' => []]);
    $run = [...PULL_REQUESTS_RUN, 'GITHUB_API_URL' => 'https://github.example/api/v3'];

    $changes = PassedPullRequests::over($source(), new MockHttpClient($response), $run)->changesSince(Revision::ref('base'));

    expect($changes)->toEqual($uncommitted())
        ->and($response->getRequestUrl())->toBe('https://github.example/api/v3/repos/octo/gate/compare/base...head')
        ->and($response->getRequestOptions()['headers'])->toContain('Authorization: Bearer secret');
});

it('is the source underneath where the environment lacks what names a run', function (string $name) use ($source): void {
    $underneath = $source();
    $environment = array_diff_key(PULL_REQUESTS_RUN, [$name => true]);

    expect(PassedPullRequests::over($underneath, answering([]), $environment))->toBe($underneath);
})->with(['GITHUB_REPOSITORY', 'GITHUB_SHA', 'GITHUB_WORKFLOW_REF']);

it('is the source underneath where the environment names no run', function (string $name, string $value) use ($source): void {
    $underneath = $source();
    $environment = [...PULL_REQUESTS_RUN, $name => $value];

    expect(PassedPullRequests::over($underneath, answering([]), $environment))->toBe($underneath);
})->with([
    'an empty repository' => ['GITHUB_REPOSITORY', ''],
    'a workflow spelt some other way' => ['GITHUB_WORKFLOW_REF', 'gate.yml'],
]);

it('asks GitHub at its own address with no token where the environment names neither', function () use ($source): void {
    $response = new JsonMockResponse(['total_commits' => 0, 'commits' => []]);
    $run = array_diff_key(PULL_REQUESTS_RUN, ['GITHUB_TOKEN' => true]);

    PassedPullRequests::over($source(), new MockHttpClient($response), $run)->changesSince(Revision::ref('base'));

    expect($response->getRequestUrl())->toBe('https://api.github.com/repos/octo/gate/compare/base...head')
        ->and($response->getRequestOptions()['headers'])->not->toContain('Authorization: Bearer secret');
});

it('is a source of its own where the environment names the run', function () use ($source): void {
    expect(PassedPullRequests::over($source(), answering([]), PULL_REQUESTS_RUN))->toBeInstanceOf(PassedPullRequests::class);
});

it('reads files and fingerprints from the source underneath', function () use ($source): void {
    $proved = PassedPullRequests::over($source(), answering([]), PULL_REQUESTS_RUN);

    expect($proved->fileAt(Path::of('src/Money.php'), Revision::ref('base')))->toEqual(Contents::of('base'))
        ->and($proved->fingerprints())->toEqual($source()->fingerprints());
});
