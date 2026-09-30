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
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Checkout;
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
    'GITHUB_TOKEN' => 'secret',
];

/** The check-run the verdict reports under, as `ci.check` names it. */
const PULL_REQUESTS_CHECK = 'mutation / verdict';

$uncommitted = static fn(): Changes => Changes::of(Change::modified(Path::of('src/Money.php'), Lines::none()));

$source = static fn(): Checkout => Checkout::of(
    new ChangeSourceFake(
        Revision::ref('head'),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::none())),
        ['base' => ['src/Money.php' => 'base'], Revision::workingTree()->name() => ['src/Money.php' => 'disk']],
    ),
    RepositoryFake::onMain(Revision::ref('head')),
);

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

/** Where GitHub lists the verdict's check-runs on a commit. */
function checkRuns(string $commit): string
{
    return sprintf('%s/commits/%s/check-runs?check_name=mutation%%20%%2F%%20verdict&status=completed', PULL_REQUESTS_API, $commit);
}

/**
 * The ledgers of pull requests numbered from one, each of which records its
 * head `pr-<commit>` as passed under the check, with none of its own proofs.
 *
 * @param list<string> $commits
 */
function passedLedgers(array $commits): ProofStoreFake
{
    $ledgers = new ProofStoreFake();

    foreach ($commits as $at => $commit) {
        $ledgers->write(
            Scope::pullRequest($at + 1),
            Ledger::empty()->withPassed(Passed::of(Revision::ref(sprintf('pr-%s', $commit)), PULL_REQUESTS_CHECK, 0)),
        );
    }

    return $ledgers;
}

/**
 * GitHub's source over the other, reading pull requests' ledgers from this store.
 *
 * @param array<string, string> $environment
 */
function trusting(Checkout $source, MockHttpClient $github, ProofStoreFake $ledgers, array $environment = PULL_REQUESTS_RUN): ChangeSource&Repository
{
    $over = PassedPullRequests::over($source, $github, $environment, PULL_REQUESTS_CHECK);

    return $over instanceof PassedPullRequests ? $over->trusting($ledgers) : $over;
}

/**
 * What GitHub says of commits each of which is the tree of a merged pull
 * request whose verdict passed on its head.
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

    foreach ($commits as $at => $commit) {
        $answers[sprintf('%s/commits/%s/pulls', PULL_REQUESTS_API, $commit)] = [['number' => $at + 1, 'merged_at' => '2026-09-30T10:00:00Z', 'head' => ['sha' => sprintf('pr-%s', $commit)]]];
        $answers[sprintf('%s/git/commits/pr-%s', PULL_REQUESTS_API, $commit)] = ['tree' => ['sha' => sprintf('tree-%s', $commit)]];
        $answers[checkRuns(sprintf('pr-%s', $commit))] = [
            'check_runs' => [
                ['name' => 'lint', 'conclusion' => 'failure'],
                ['name' => PULL_REQUESTS_CHECK, 'conclusion' => 'success'],
            ],
        ];
    }

    return $answers;
}

it('reads only what is uncommitted when every commit since the base is the tree of a pull request whose run passed', function () use ($source, $uncommitted): void {
    $proved = trusting($source(), answering(provedCommits(['one', 'two'])), passedLedgers(['one', 'two']));

    expect($proved->changesSince(Revision::ref('base')))->toEqual($uncommitted());
});

it('proves nothing without the pull requests\' ledgers to read', function () use ($source): void {
    $untrusted = PassedPullRequests::over($source(), answering(provedCommits(['one'])), PULL_REQUESTS_RUN, PULL_REQUESTS_CHECK);

    expect($untrusted->changesSince(Revision::ref('base')))
        ->toEqual(CannotTell::because('base is not a revision this repository has.'));
});

it('reads only what is uncommitted when the base is the head', function () use ($source, $uncommitted): void {
    $proved = trusting($source(), answering(provedCommits([])), passedLedgers([]));

    expect($proved->changesSince(Revision::ref('base')))->toEqual($uncommitted());
});

it('asks about twenty commits, and no more', function (int $commits, bool $proved) use ($source): void {
    $names = array_map(static fn(int $commit): string => sprintf('c%d', $commit), range(1, $commits));
    $changes = trusting($source(), answering(provedCommits($names)), passedLedgers($names))->changesSince(Revision::ref('base'));

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
        'no check-run is known' => array_diff_key($answers, [checkRuns('pr-two') => true]),
        'the check failed' => [...$answers, checkRuns('pr-two') => [
            'check_runs' => [['name' => 'lint', 'conclusion' => 'success'], ['name' => PULL_REQUESTS_CHECK, 'conclusion' => 'failure']],
        ]],
        'the pull request has no number' => [...$answers, sprintf('%s/commits/two/pulls', PULL_REQUESTS_API) => [['merged_at' => '2026-09-30T10:00:00Z', 'head' => ['sha' => 'pr-two']]]],
        'the commit names no tree' => [
            ...$answers,
            sprintf('%s/compare/base...head', PULL_REQUESTS_API) => ['total_commits' => 1, 'commits' => [['sha' => 'one', 'commit' => []]]],
            sprintf('%s/git/commits/pr-one', PULL_REQUESTS_API) => ['tree' => []],
        ],
        default => $answers,
    };
}

it('reads everything since the base when a commit cannot be proved', function (string $how) use ($source): void {
    $changes = trusting($source(), answering(spoilt($how, provedCommits(['one', 'two']))), passedLedgers(['one', 'two']))
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
    'no check-run is known',
    'the check failed',
    'the pull request has no number',
    'the commit names no tree',
]);

it('reads everything since the base when a pull request\'s ledger does not vouch for its head', function (Ledger $ledger) use ($source): void {
    $ledgers = passedLedgers(['one', 'two']);
    $ledgers->write(Scope::pullRequest(2), $ledger);

    expect(trusting($source(), answering(provedCommits(['one', 'two'])), $ledgers)->changesSince(Revision::ref('base')))
        ->toEqual(CannotTell::because('base is not a revision this repository has.'));
})->with([
    'no pass recorded' => [Ledger::empty()],
    'another head passed' => [Ledger::empty()->withPassed(Passed::of(Revision::ref('pr-other'), PULL_REQUESTS_CHECK, 0))],
    'another check passed' => [Ledger::empty()->withPassed(Passed::of(Revision::ref('pr-two'), 'lint', 0))],
    'its own proofs were used' => [Ledger::empty()->withPassed(Passed::of(Revision::ref('pr-two'), PULL_REQUESTS_CHECK, 1))],
]);

it('reads everything since the base when the pull requests\' ledgers cannot be read', function () use ($source): void {
    $ledgers = ProofStoreFake::unreadable(
        Unreadable::because(UnreadReason::TimedOut, 'https://ledgers.example.com', 'no answer came in time'),
    );

    expect(trusting($source(), answering(provedCommits(['one', 'two'])), $ledgers)->changesSince(Revision::ref('base')))
        ->toEqual(CannotTell::because('base is not a revision this repository has.'));
});

it('asks GitHub where GITHUB_API_URL says, with GITHUB_TOKEN', function () use ($source, $uncommitted): void {
    $response = new JsonMockResponse(['total_commits' => 0, 'commits' => []]);
    $run = [...PULL_REQUESTS_RUN, 'GITHUB_API_URL' => 'https://github.example/api/v3'];

    $changes = trusting($source(), new MockHttpClient($response), passedLedgers([]), $run)->changesSince(Revision::ref('base'));

    expect($changes)->toEqual($uncommitted())
        ->and($response->getRequestUrl())->toBe('https://github.example/api/v3/repos/octo/gate/compare/base...head')
        ->and($response->getRequestOptions()['headers'])->toContain('Authorization: Bearer secret');
});

it('is the source underneath where the environment lacks what names a run', function (string $name) use ($source): void {
    $underneath = $source();
    $environment = array_diff_key(PULL_REQUESTS_RUN, [$name => true]);

    expect(PassedPullRequests::over($underneath, answering([]), $environment, PULL_REQUESTS_CHECK))->toBe($underneath);
})->with(['GITHUB_REPOSITORY', 'GITHUB_SHA']);

it('is the source underneath where the environment names no run', function (string $name, string $value) use ($source): void {
    $underneath = $source();
    $environment = [...PULL_REQUESTS_RUN, $name => $value];

    expect(PassedPullRequests::over($underneath, answering([]), $environment, PULL_REQUESTS_CHECK))->toBe($underneath);
})->with([
    'an empty repository' => ['GITHUB_REPOSITORY', ''],
    'an empty commit' => ['GITHUB_SHA', ''],
]);

it('is the source underneath where no check names the verdict', function () use ($source): void {
    $underneath = $source();

    expect(PassedPullRequests::over($underneath, answering([]), PULL_REQUESTS_RUN, ''))->toBe($underneath);
});

it('asks GitHub at its own address with no token where the environment names neither', function () use ($source): void {
    $response = new JsonMockResponse(['total_commits' => 0, 'commits' => []]);
    $run = array_diff_key(PULL_REQUESTS_RUN, ['GITHUB_TOKEN' => true]);

    trusting($source(), new MockHttpClient($response), passedLedgers([]), $run)->changesSince(Revision::ref('base'));

    expect($response->getRequestUrl())->toBe('https://api.github.com/repos/octo/gate/compare/base...head')
        ->and($response->getRequestOptions()['headers'])->not->toContain('Authorization: Bearer secret');
});

it('is a source of its own where the environment names the run', function () use ($source): void {
    expect(PassedPullRequests::over($source(), answering([]), PULL_REQUESTS_RUN, PULL_REQUESTS_CHECK))
        ->toBeInstanceOf(PassedPullRequests::class);
});

it('reads files and fingerprints from the source underneath', function () use ($source): void {
    $proved = trusting($source(), answering([]), passedLedgers([]));

    expect($proved->fileAt(Path::of('src/Money.php'), Revision::ref('base')))->toEqual(Contents::of('base'))
        ->and($proved->filesAt(Paths::of(Path::of('src/Money.php')), Revision::ref('base')))->toEqual($source()->filesAt(Paths::of(Path::of('src/Money.php')), Revision::ref('base')))
        ->and($proved->fingerprints())->toEqual($source()->fingerprints());
});

it('says where the checkout stands as the source underneath says', function (): void {
    $underneath = Checkout::of(ChangeSourceFake::ofTheFixture(), RepositoryFake::detachedAt(Revision::ref('5eeca8f')));
    $proved = trusting($underneath, answering([]), passedLedgers([]));

    expect($proved->head())->toEqual(Revision::ref('5eeca8f'))
        ->and($proved->branch())->toEqual($underneath->branch())
        ->and($proved->defaultBranch())->toEqual($underneath->defaultBranch());
});
