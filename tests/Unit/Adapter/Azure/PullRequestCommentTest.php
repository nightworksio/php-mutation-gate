<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\PullRequestComment;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Comment\Passing;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\CommentSettings;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Config\CommentToggles;
use NightWorksIO\MutationGate\Core\Config\CommentWrites;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Report\RecheckedMarkdown;
use NightWorksIO\MutationGate\Core\Report\UnfoldedMarkdown;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

const AZURE_PULL_REQUEST = 'https://dev.azure.com/acme/My%20Shop/_apis/git/repositories/repo-id/pullRequests/7';

const AZURE_THREADS = 'https://dev.azure.com/acme/My%20Shop/_apis/git/repositories/repo-id/pullRequests/7/threads';

const AZURE_PAGE = 'https://dev.azure.com/acme/My%20Shop/_git/shop/pullrequest/7';

const AZURE_PIPELINE = 'https://dev.azure.com/acme/My%20Shop/_build/results?buildId=99';

/**
 * The variables of a pipeline job building a pull request, holding a token, with these set.
 *
 * @param array<string, string> $with
 */
function azurePullRequest(array $with = []): Variables
{
    return Variables::of([
        'TF_BUILD' => 'True',
        'SYSTEM_COLLECTIONURI' => 'https://dev.azure.com/acme/',
        'SYSTEM_TEAMPROJECT' => 'My Shop',
        'BUILD_REPOSITORY_ID' => 'repo-id',
        'BUILD_REPOSITORY_NAME' => 'shop',
        'BUILD_BUILDID' => '99',
        'SYSTEM_PULLREQUEST_PULLREQUESTID' => '7',
        'SYSTEM_ACCESSTOKEN' => 'azure-secret',
        ...$with,
    ]);
}

function azureVersioned(string $url): string
{
    return sprintf('%s?api-version=7.1', $url);
}

/**
 * A thread as Azure Repos lists it, its first comment holding this.
 *
 * @return array<string, mixed>
 */
function azureThread(int $id, string $content, string $author = 'gate', string $status = 'active'): array
{
    return ['id' => $id, 'status' => $status, 'comments' => [['id' => $id * 10, 'content' => $content, 'author' => ['id' => $author]]]];
}

/** @param list<array<string, mixed>> $threads */
function azureListing(array $threads): Cloud
{
    return new Cloud()->answeringTo('GET', azureVersioned(AZURE_THREADS), 200, (string) json_encode(['value' => $threads]));
}

/** The answer for a comment put in place by this identity. */
function azureUpdated(string $author = 'gate'): string
{
    return (string) json_encode(['id' => 1, 'author' => ['id' => $author]]);
}

/** The answer for a thread begun by this identity. */
function azureBegun(int $id, string $author = 'gate'): string
{
    return (string) json_encode(['id' => $id, 'comments' => [['id' => 1, 'author' => ['id' => $author]]]]);
}

function azureCommentUrl(int $thread): string
{
    return azureVersioned(sprintf('%s/%d/comments/%d', AZURE_THREADS, $thread, $thread * 10));
}

function azureMarked(string $after = ''): string
{
    return sprintf("%s\n\n%s", UnfoldedMarkdown::MARKER, $after);
}

function azureCommentOn(Cloud $cloud, CommentSettings $settings, Variables $variables): PullRequestComment
{
    return PullRequestComment::inEnvironment($variables, $cloud->exchange(), 'SYSTEM_ACCESSTOKEN', $settings);
}

function azureStandardOn(Cloud $cloud): PullRequestComment
{
    return azureCommentOn($cloud, CommentSettings::standard(), azurePullRequest());
}

/** @return list<string> */
function azureCalls(Cloud $cloud): array
{
    return array_map(static fn(array $request): string => sprintf('%s %s', $request['method'], $request['url']), $cloud->requests);
}

it('begins a thread where none holds the marker, with the token, linking the pipeline, and says where it is', function (): void {
    $cloud = azureListing([azureThread(1, 'Looks good')])
        ->answeringTo('POST', azureVersioned(AZURE_THREADS), 200, azureBegun(9));
    $answer = azureStandardOn($cloud)->report(Verdicts::failing());
    $posted = $cloud->requests[1];

    expect($answer)->toEqual(Written::to(sprintf('%s?discussionId=9', AZURE_PAGE)))
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET', 'POST'])
        ->and($posted['url'])->toBe(azureVersioned(AZURE_THREADS))
        ->and($posted['headers']['authorization'] ?? '')->toBe('Bearer azure-secret')
        ->and(Decoded::at($posted['body'], 'status'))->toBe(1)
        ->and(Decoded::at($posted['body'], 'comments', 0, 'parentCommentId'))->toBe(0)
        ->and(Decoded::at($posted['body'], 'comments', 0, 'commentType'))->toBe(1)
        ->and(Decoded::at($posted['body'], 'comments', 0, 'content'))
        ->toBe(UnfoldedMarkdown::of(Markdown::comment(Verdicts::failing(), CommentShows::of()), AZURE_PIPELINE));
});

it('puts its own comment in place, passing over one holding the marker the token may not edit', function (int $refused): void {
    $cloud = azureListing([azureThread(3, azureMarked('planted'), 'someone'), azureThread(5, azureMarked('old')), azureThread(6, azureMarked('later'))])
        ->answering(azureCommentUrl(3), $refused, '{}')
        ->answering(azureCommentUrl(5), 200, azureUpdated());

    expect(azureStandardOn($cloud)->report(Verdicts::passing()))->toEqual(Written::to(sprintf('%s?discussionId=5', AZURE_PAGE)))
        ->and(azureCalls($cloud))->toBe([
            sprintf('GET %s', azureVersioned(AZURE_THREADS)),
            sprintf('PATCH %s', azureCommentUrl(3)),
            sprintf('PATCH %s', azureCommentUrl(5)),
        ])
        ->and(Decoded::at($cloud->requests[2]['body'], 'content'))
        ->toBe(UnfoldedMarkdown::of(Markdown::comment(Verdicts::passing(), CommentShows::of()), AZURE_PIPELINE));
})->with(['forbidden' => [403], 'not found' => [404]]);

it('tries ten threads holding the marker, and then begins a new one', function (): void {
    $cloud = azureListing(array_map(static fn(int $id): array => azureThread($id, azureMarked()), range(1, 11)))
        ->answeringTo('POST', azureVersioned(AZURE_THREADS), 200, azureBegun(12));

    foreach (range(1, 11) as $id) {
        $cloud->answering(azureCommentUrl($id), 403, '{}');
    }

    expect(azureStandardOn($cloud)->report(Verdicts::passing()))->toEqual(Written::to(sprintf('%s?discussionId=12', AZURE_PAGE)))
        ->and(array_count_values(array_column($cloud->requests, 'method')))->toBe(['GET' => 1, 'PATCH' => 10, 'POST' => 1]);
});

it('passes over a thread with no comment left to read', function (): void {
    $cloud = azureListing([['id' => 2, 'comments' => []], azureThread(3, azureMarked())])
        ->answering(azureCommentUrl(3), 200, azureUpdated());

    expect(azureStandardOn($cloud)->report(Verdicts::passing()))->toEqual(Written::to(sprintf('%s?discussionId=3', AZURE_PAGE)));
});

it('asks Azure Repos whether the pull request is a draft only where comment.drafts keeps one quiet', function (string $answer, bool $left): void {
    $cloud = azureListing([])
        ->answering(azureVersioned(AZURE_PULL_REQUEST), 200, $answer)
        ->answeringTo('POST', azureVersioned(AZURE_THREADS), 200, azureBegun(9));
    $settings = CommentSettings::of(CommentShows::of(), CommentWrites::of(toggles: CommentToggles::off(Toggle::Drafts)));

    expect(azureCommentOn($cloud, $settings, azurePullRequest())->report(Verdicts::failing()))->toEqual($left
        ? NotWritten::because('comment.drafts is false, so this run writes no comment.')
        : Written::to(sprintf('%s?discussionId=9', AZURE_PAGE)));
})->with([
    'a draft' => ['{"isDraft": true}', true],
    'no draft' => ['{"isDraft": false}', false],
    'one that does not say' => ['{}', false],
]);

it('asks no question of a run on no pull request, and of one where drafts comment', function (): void {
    $none = new Cloud();
    $drafts = azureListing([])->answeringTo('POST', azureVersioned(AZURE_THREADS), 200, azureBegun(9));
    $offs = CommentSettings::of(CommentShows::of(), CommentWrites::of(toggles: CommentToggles::off(Toggle::Drafts)));

    azureCommentOn($none, $offs, azurePullRequest(['SYSTEM_PULLREQUEST_PULLREQUESTID' => '']));
    azureStandardOn($drafts)->report(Verdicts::failing());

    expect($none->requests)->toBe([])
        ->and(array_column($drafts->requests, 'method'))->toBe(['GET', 'POST']);
});

it('writes its planned state into the thread the verdict later replaces', function (): void {
    $cloud = azureListing([azureThread(5, azureMarked())])->answering(azureCommentUrl(5), 200, azureUpdated());

    expect(azureStandardOn($cloud)->planned(ShardedPlan::planned(2)))->toEqual(Written::to(sprintf('%s?discussionId=5', AZURE_PAGE)))
        ->and(Decoded::at($cloud->requests[1]['body'], 'content'))
        ->toBe(UnfoldedMarkdown::of(PlannedMarkdown::comment(ShardedPlan::planned(2), AZURE_PIPELINE), AZURE_PIPELINE));
});

it('writes the re-checked survivors over its own planned state, and puts back as it was its own comment past it', function (): void {
    $planned = UnfoldedMarkdown::of(PlannedMarkdown::comment(ShardedPlan::planned(2), ''), '');
    $judged = UnfoldedMarkdown::of(Markdown::comment(Verdicts::failing(), CommentShows::of()), '');
    $threads = static fn(string $own): Cloud => azureListing([azureThread(3, $planned, 'someone'), azureThread(5, $own)])
        ->answering(azureCommentUrl(3), 403, '{}')
        ->answering(azureCommentUrl(5), 200, azureUpdated());
    $overPlanned = $threads($planned);
    $overJudged = $threads($judged);

    expect(azureStandardOn($overPlanned)->rechecked(Rechecks::mixed()))->toEqual(Written::to(sprintf('%s?discussionId=5', AZURE_PAGE)))
        ->and(Decoded::at($overPlanned->requests[2]['body'], 'content'))
        ->toBe(UnfoldedMarkdown::of(RecheckedMarkdown::comment(Rechecks::mixed(), AZURE_PIPELINE, CommentShows::of()), AZURE_PIPELINE))
        ->and(azureStandardOn($overJudged)->rechecked(Rechecks::mixed()))->toEqual(NotWritten::because(CommentStates::NOT_PLANNED))
        ->and(Decoded::at($overJudged->requests[2]['body'], 'content'))->toBe($judged);
});

it('writes no re-checked survivors where no thread is its own', function (): void {
    $cloud = azureListing([]);

    expect(azureStandardOn($cloud)->rechecked(Rechecks::mixed()))->toEqual(NotWritten::because(CommentStates::NOT_PLANNED))
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET']);
});

it('writes nothing for a passing run under comment.passing: update where the gate has not commented', function (): void {
    $cloud = azureListing([azureThread(1, 'Looks good')]);

    expect(azureCommentOn($cloud, CommentSettings::of(CommentShows::of(), CommentWrites::of(passing: Passing::Update)), azurePullRequest())->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(CommentStates::NOT_THERE))
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET']);
});

it('closes the threads of the runs before it under new-hiding-old, and nobody else\'s or those already closed', function (): void {
    $settings = CommentSettings::of(CommentShows::of(), CommentWrites::of(mode: Mode::NewHidingOld));
    $cloud = azureListing([
        azureThread(1, azureMarked('older')),
        azureThread(2, azureMarked('quoted'), 'someone'),
        azureThread(3, azureMarked('closed'), 'gate', 'closed'),
        azureThread(4, 'Looks good'),
    ])->answeringTo('POST', azureVersioned(AZURE_THREADS), 200, azureBegun(9))
        ->answering(azureVersioned(sprintf('%s/1', AZURE_THREADS)), 200, '{}');

    expect(azureCommentOn($cloud, $settings, azurePullRequest())->report(Verdicts::failing()))->toEqual(Written::to(sprintf('%s?discussionId=9', AZURE_PAGE)))
        ->and(azureCalls($cloud))->toBe([
            sprintf('GET %s', azureVersioned(AZURE_THREADS)),
            sprintf('POST %s', azureVersioned(AZURE_THREADS)),
            sprintf('PATCH %s', azureVersioned(sprintf('%s/1', AZURE_THREADS))),
        ])
        ->and(Decoded::at($cloud->requests[2]['body'], 'status'))->toBe(4);
});

it('keeps the thread it wrote open under new-hiding-old on a re-run of the same job', function (): void {
    $settings = CommentSettings::of(CommentShows::of(), CommentWrites::of(mode: Mode::NewHidingOld));
    $cloud = azureListing([azureThread(1, azureMarked(UnfoldedMarkdown::of(sprintf(CommentWrite::RUN, AZURE_PIPELINE), '')))])
        ->answering(azureCommentUrl(1), 200, azureUpdated());

    expect(azureCommentOn($cloud, $settings, azurePullRequest())->report(Verdicts::failing()))->toEqual(Written::to(sprintf('%s?discussionId=1', AZURE_PAGE)))
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET', 'PATCH']);
});

it('closes nothing when the comment could not be written', function (): void {
    $settings = CommentSettings::of(CommentShows::of(), CommentWrites::of(mode: Mode::NewHidingOld));
    $cloud = azureListing([azureThread(1, azureMarked('older'))])->answeringTo('POST', azureVersioned(AZURE_THREADS), 500, '{"message": "down"}');

    expect(azureCommentOn($cloud, $settings, azurePullRequest())->report(Verdicts::failing()))->toBeInstanceOf(NotWritten::class)
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET', 'POST']);
});

it('writes no comment, and says why, where the job cannot write one', function (Variables $environment, string $why): void {
    $cloud = new Cloud();

    expect(azureCommentOn($cloud, CommentSettings::standard(), $environment)->report(Verdicts::failing()))->toEqual(NotWritten::because($why))
        ->and($cloud->requests)->toBe([]);
})->with([
    'no pull request' => [fn(): Variables => azurePullRequest(['SYSTEM_PULLREQUEST_PULLREQUESTID' => '']), 'This run is not for a pull request, so there is no comment to write.'],
    'no token' => [fn(): Variables => azurePullRequest(['SYSTEM_ACCESSTOKEN' => '']), 'SYSTEM_ACCESSTOKEN is not set, so no comment is written.'],
    'a fork' => [fn(): Variables => azurePullRequest(['SYSTEM_PULLREQUEST_ISFORK' => 'True']), 'A fork\'s pull request build holds no token that can comment, so no comment is written.'],
]);

it('writes on a pull request that is no fork', function (): void {
    $cloud = azureListing([])->answeringTo('POST', azureVersioned(AZURE_THREADS), 200, azureBegun(9));

    expect(azureCommentOn($cloud, CommentSettings::standard(), azurePullRequest(['SYSTEM_PULLREQUEST_ISFORK' => 'False']))->report(Verdicts::failing()))
        ->toBeInstanceOf(Written::class);
});

it('reads its token from the variable tokenEnv names, SYSTEM_ACCESSTOKEN by default', function (): void {
    $environment = azurePullRequest(['SYSTEM_ACCESSTOKEN' => '', 'GATE_COMMENTS' => 'azure-other']);
    $cloud = azureListing([])->answeringTo('POST', azureVersioned(AZURE_THREADS), 200, azureBegun(2));

    expect(PullRequestComment::configured(Configs::options('{"tokenEnv": "GATE_COMMENTS"}'), $environment, $cloud->exchange())->report(Verdicts::failing()))
        ->toBeInstanceOf(Written::class)
        ->and($cloud->requests[0]['headers']['authorization'] ?? '')->toBe('Bearer azure-other')
        ->and(PullRequestComment::configured(Configs::options('{}'), $environment, new Cloud()->exchange())->report(Verdicts::failing()))
        ->toEqual(NotWritten::because('SYSTEM_ACCESSTOKEN is not set, so no comment is written.'));
});

it('says why, naming Azure by its origin alone and never the token, where it refuses', function (string $method, string $refused, int $status): void {
    $cloud = azureListing([azureThread(5, azureMarked())])
        ->answering(azureCommentUrl(5), 200, azureUpdated())
        ->answeringTo($method, $refused, $status, '{"message": "no"}');

    expect(azureStandardOn($cloud)->report(Verdicts::passing()))->toEqual(NotWritten::because(sprintf(
        'The pull request comment could not be written (https://dev.azure.com answered %d: {"message": "no"}).',
        $status,
    )));
})->with([
    'the threads' => ['GET', fn(): string => azureVersioned(AZURE_THREADS), 401],
    'the update' => ['PATCH', fn(): string => azureCommentUrl(5), 500],
]);

it('leaves its comment, and its planned state, for deliver on a pull request, token or not', function (string $token): void {
    $cloud = new Cloud();
    $comment = azureCommentOn($cloud, CommentSettings::standard(), azurePullRequest(['SYSTEM_ACCESSTOKEN' => $token]));

    expect($comment->deferred(Verdicts::failing(), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(Markdown::comment(Verdicts::failing(), CommentShows::of()), CommentWrite::sticky()))
        ->and($comment->plannedLater(ShardedPlan::planned(2), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(PlannedMarkdown::comment(ShardedPlan::planned(2), AZURE_PIPELINE), CommentWrite::sticky()))
        ->and($comment->recheckedLater(Rechecks::mixed(), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(RecheckedMarkdown::comment(Rechecks::mixed(), AZURE_PIPELINE, CommentShows::of()), CommentWrite::sticky()->overPlanned()))
        ->and($cloud->requests)->toBe([]);
})->with(['with a token' => ['azure-secret'], 'with none' => ['']]);

it('writes what deliver holds as it says', function (): void {
    $cloud = azureListing([])->answeringTo('POST', azureVersioned(AZURE_THREADS), 200, azureBegun(9));

    expect(azureStandardOn($cloud)->writeAs('Held', CommentWrite::sticky()))->toEqual(Written::to(sprintf('%s?discussionId=9', AZURE_PAGE)))
        ->and(Decoded::at($cloud->requests[1]['body'], 'comments', 0, 'content'))->toBe(UnfoldedMarkdown::of('Held', AZURE_PIPELINE));
});

it('encodes the project, the repository and its name wherever they name the pull request', function (): void {
    $threads = 'https://dev.azure.com/acme/R%26D/_apis/git/repositories/repo%2Fid/pullRequests/7/threads';
    $cloud = new Cloud()
        ->answeringTo('GET', azureVersioned($threads), 200, '{"value": []}')
        ->answeringTo('POST', azureVersioned($threads), 200, azureBegun(9));
    $environment = azurePullRequest(['SYSTEM_TEAMPROJECT' => 'R&D', 'BUILD_REPOSITORY_ID' => 'repo/id', 'BUILD_REPOSITORY_NAME' => 'web&shop']);

    expect(azureCommentOn($cloud, CommentSettings::standard(), $environment)->report(Verdicts::failing()))
        ->toEqual(Written::to('https://dev.azure.com/acme/R%26D/_git/web%26shop/pullrequest/7?discussionId=9'))
        ->and(azureCalls($cloud))->toBe([sprintf('GET %s', azureVersioned($threads)), sprintf('POST %s', azureVersioned($threads))]);
});

it('leaves nothing for deliver on a run for no pull request', function (): void {
    $comment = azureCommentOn(new Cloud(), CommentSettings::standard(), azurePullRequest(['SYSTEM_PULLREQUEST_PULLREQUESTID' => '']));

    expect($comment->deferred(Verdicts::failing(), Delivery::none()))->toEqual(Delivery::none())
        ->and($comment->plannedLater(ShardedPlan::planned(2), Delivery::none()))->toEqual(Delivery::none())
        ->and($comment->recheckedLater(Rechecks::mixed(), Delivery::none()))->toEqual(Delivery::none());
});
