<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Bitbucket\CommentMarkdown;
use NightWorksIO\MutationGate\Adapter\Bitbucket\PullRequestComment;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Report\RecheckedMarkdown;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

const BITBUCKET_COMMENTS = 'https://api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/7/comments';

const BITBUCKET_PIPELINE = 'https://bitbucket.org/acme/shop/pipelines/results/99';

/**
 * The variables of a pull request pipeline's step in the repository acme/shop, holding a token, with these set.
 *
 * @param array<string, string> $with
 */
function bitbucketPullRequest(array $with = []): Variables
{
    return Variables::of([
        'BITBUCKET_BUILD_NUMBER' => '99',
        'BITBUCKET_REPO_FULL_NAME' => 'acme/shop',
        'BITBUCKET_WORKSPACE' => 'acme',
        'BITBUCKET_REPO_SLUG' => 'shop',
        'BITBUCKET_BRANCH' => 'feature',
        'BITBUCKET_COMMIT' => '5eeca8f',
        'BITBUCKET_PR_ID' => '7',
        'MUTATION_GATE_BITBUCKET_TOKEN' => 'bb-secret',
        ...$with,
    ]);
}

/**
 * A comment as Bitbucket's pull request comments API lists it.
 *
 * @param  array<string, mixed> $more
 * @return array<string, mixed>
 */
function bitbucketComment(int $id, string $raw, array $more = []): array
{
    return ['id' => $id, 'content' => ['raw' => $raw], ...$more];
}

/** The URL of a page of the pull request's comments. */
function bitbucketPage(int $page): string
{
    return sprintf('%s?pagelen=50&page=%d', BITBUCKET_COMMENTS, $page);
}

/** The answer for a comment written, at its page. */
function bitbucketWritten(int $id): string
{
    return (string) json_encode(['id' => $id, 'links' => ['html' => ['href' => sprintf('https://bitbucket.org/acme/shop/pull-requests/7#comment-%d', $id)]]]);
}

/** A comment holding the Bitbucket marker. */
function bitbucketMarked(string $after = ''): string
{
    return sprintf("%s\n\n%s", CommentMarkdown::MARKER, $after);
}

it('posts a new comment where none holds the marker, with the token, linking the pipeline', function (): void {
    $cloud = new Cloud()
        ->answering(bitbucketPage(1), 200, (string) json_encode(['values' => [
            bitbucketComment(1, 'Looks good'),
            bitbucketComment(2, bitbucketMarked(), ['deleted' => true]),
            bitbucketComment(3, bitbucketMarked(), ['parent' => ['id' => 1]]),
            bitbucketComment(4, bitbucketMarked(), ['inline' => ['path' => 'src/A.php']]),
        ]]))
        ->answering(BITBUCKET_COMMENTS, 201, bitbucketWritten(9));
    $answer = PullRequestComment::inEnvironment(bitbucketPullRequest(), $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')
        ->report(Verdicts::failing());
    $posted = $cloud->requests[1];

    expect($answer)->toEqual(Written::to('https://bitbucket.org/acme/shop/pull-requests/7#comment-9'))
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET', 'POST'])
        ->and($posted['url'])->toBe(BITBUCKET_COMMENTS)
        ->and($posted['headers']['authorization'] ?? '')->toBe('Bearer bb-secret')
        ->and(Decoded::at($posted['body'], 'content', 'raw'))->toBe(CommentMarkdown::of(Markdown::comment(Verdicts::failing()), BITBUCKET_PIPELINE))
        ->and(Decoded::at($posted['body'], 'content', 'raw'))->toStartWith(CommentMarkdown::MARKER);
});

it('puts its own comment in place, passing over one holding the marker that Bitbucket will not let the token edit', function (int $refused): void {
    $cloud = new Cloud()
        ->answering(bitbucketPage(1), 200, (string) json_encode(['values' => [bitbucketComment(3, bitbucketMarked('planted'))], 'next' => bitbucketPage(2)]))
        ->answering(bitbucketPage(2), 200, (string) json_encode(['values' => [bitbucketComment(5, bitbucketMarked('old')), bitbucketComment(6, bitbucketMarked('later'))]]))
        ->answering(sprintf('%s/3', BITBUCKET_COMMENTS), $refused, '{"type": "error"}')
        ->answering(sprintf('%s/5', BITBUCKET_COMMENTS), 200, bitbucketWritten(5));
    $answer = PullRequestComment::inEnvironment(bitbucketPullRequest(), $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')
        ->report(Verdicts::passing());

    expect($answer)->toEqual(Written::to('https://bitbucket.org/acme/shop/pull-requests/7#comment-5'))
        ->and(array_map(static fn(array $request): string => sprintf('%s %s', $request['method'], $request['url']), $cloud->requests))->toBe([
            sprintf('GET %s', bitbucketPage(1)),
            sprintf('GET %s', bitbucketPage(2)),
            sprintf('PUT %s/3', BITBUCKET_COMMENTS),
            sprintf('PUT %s/5', BITBUCKET_COMMENTS),
        ]);
})->with(['forbidden' => [403], 'not found' => [404]]);

it('tries ten comments holding the marker, and then posts a new one', function (): void {
    $marked = array_map(static fn(int $id): array => bitbucketComment($id, bitbucketMarked()), range(1, 11));
    $cloud = new Cloud()->answering(bitbucketPage(1), 200, (string) json_encode(['values' => $marked]))
        ->answering(BITBUCKET_COMMENTS, 201, bitbucketWritten(12));

    foreach (range(1, 11) as $id) {
        $cloud->answering(sprintf('%s/%d', BITBUCKET_COMMENTS, $id), 403, '{}');
    }

    $answer = PullRequestComment::inEnvironment(bitbucketPullRequest(), $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')
        ->report(Verdicts::passing());

    expect($answer)->toEqual(Written::to('https://bitbucket.org/acme/shop/pull-requests/7#comment-12'))
        ->and(array_count_values(array_column($cloud->requests, 'method')))->toBe(['GET' => 1, 'PUT' => 10, 'POST' => 1]);
});

it('reads thirty pages of comments, and no more', function (): void {
    $cloud = new Cloud()->answering(BITBUCKET_COMMENTS, 201, bitbucketWritten(1));

    foreach (range(1, 31) as $page) {
        $cloud->answering(bitbucketPage($page), 200, (string) json_encode(['values' => [], 'next' => bitbucketPage($page + 1)]));
    }

    PullRequestComment::inEnvironment(bitbucketPullRequest(), $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')->report(Verdicts::passing());

    expect(array_column($cloud->requests, 'url'))->toContain(bitbucketPage(30))
        ->and(array_column($cloud->requests, 'url'))->not->toContain(bitbucketPage(31));
});

it('writes its planned state into the comment the verdict later replaces', function (): void {
    $cloud = new Cloud()
        ->answering(bitbucketPage(1), 200, (string) json_encode(['values' => [bitbucketComment(5, bitbucketMarked())]]))
        ->answering(sprintf('%s/5', BITBUCKET_COMMENTS), 200, bitbucketWritten(5));
    $answer = PullRequestComment::inEnvironment(bitbucketPullRequest(), $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')
        ->planned(ShardedPlan::planned(2));

    expect($answer)->toEqual(Written::to('https://bitbucket.org/acme/shop/pull-requests/7#comment-5'))
        ->and(Decoded::at($cloud->requests[1]['body'], 'content', 'raw'))
        ->toBe(CommentMarkdown::of(PlannedMarkdown::comment(ShardedPlan::planned(2), BITBUCKET_PIPELINE), BITBUCKET_PIPELINE));
});

it('writes the re-checked survivors over its own planned state, and puts back as it was its own comment past it', function (): void {
    $planned = CommentMarkdown::of(PlannedMarkdown::comment(ShardedPlan::planned(2), ''), '');
    $judged = CommentMarkdown::of(Markdown::comment(Verdicts::failing()), '');
    $comments = static fn(string $own): Cloud => new Cloud()
        ->answering(bitbucketPage(1), 200, (string) json_encode(['values' => [bitbucketComment(3, $planned), bitbucketComment(5, $own)]]))
        ->answering(sprintf('%s/3', BITBUCKET_COMMENTS), 403, '{}')
        ->answering(sprintf('%s/5', BITBUCKET_COMMENTS), 200, bitbucketWritten(5));
    $overPlanned = $comments($planned);
    $overJudged = $comments($judged);
    $comment = static fn(Cloud $cloud): PullRequestComment => PullRequestComment::inEnvironment(
        bitbucketPullRequest(),
        $cloud->exchange(),
        'MUTATION_GATE_BITBUCKET_TOKEN',
    );

    expect($comment($overPlanned)->rechecked(Rechecks::mixed()))
        ->toEqual(Written::to('https://bitbucket.org/acme/shop/pull-requests/7#comment-5'))
        ->and(Decoded::at($overPlanned->requests[2]['body'], 'content', 'raw'))
        ->toBe(CommentMarkdown::of(RecheckedMarkdown::comment(Rechecks::mixed(), BITBUCKET_PIPELINE), BITBUCKET_PIPELINE))
        ->and($comment($overJudged)->rechecked(Rechecks::mixed()))->toEqual(NotWritten::because(CommentStates::NOT_PLANNED))
        ->and(Decoded::at($overJudged->requests[2]['body'], 'content', 'raw'))->toBe($judged);
});

it('says why, and leaves the comment, where Bitbucket fails to put back a comment past its planned state', function (): void {
    $judged = CommentMarkdown::of(Markdown::comment(Verdicts::failing()), '');
    $cloud = new Cloud()
        ->answering(bitbucketPage(1), 200, (string) json_encode(['values' => [bitbucketComment(5, $judged)]]))
        ->answering(sprintf('%s/5', BITBUCKET_COMMENTS), 500, '{"error": {"message": "down"}}');

    expect(PullRequestComment::inEnvironment(bitbucketPullRequest(), $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')->rechecked(Rechecks::mixed()))
        ->toEqual(NotWritten::because(
            'The pull request comment could not be written (https://api.bitbucket.org answered 500: {"error": {"message": "down"}}).',
        ));
});

it('links no pipeline in the planned state left for deliver where it names none', function (): void {
    $comment = PullRequestComment::inEnvironment(Variables::of(['BITBUCKET_PR_ID' => '7']), new Cloud()->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN');

    expect($comment->plannedLater(ShardedPlan::planned(2), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(PlannedMarkdown::comment(ShardedPlan::planned(2), '')));
});

it('writes no re-checked survivors where no comment is its own', function (): void {
    $cloud = new Cloud()->answering(bitbucketPage(1), 200, '{"values": []}');

    expect(PullRequestComment::inEnvironment(bitbucketPullRequest(), $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')->rechecked(Rechecks::mixed()))
        ->toEqual(NotWritten::because(CommentStates::NOT_PLANNED))
        ->and(array_column($cloud->requests, 'method'))->toBe(['GET']);
});

it('writes no comment, and says why, where the step cannot write one', function (Variables $environment, string $why): void {
    $cloud = new Cloud();
    $answer = PullRequestComment::inEnvironment($environment, $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')
        ->report(Verdicts::failing());

    expect($answer)->toEqual(NotWritten::because($why))
        ->and($cloud->requests)->toBe([]);
})->with([
    'no pull request' => [fn(): Variables => bitbucketPullRequest(['BITBUCKET_PR_ID' => '']), 'This run is not for a pull request, so there is no comment to write.'],
    'no token' => [fn(): Variables => bitbucketPullRequest(['MUTATION_GATE_BITBUCKET_TOKEN' => '']), 'MUTATION_GATE_BITBUCKET_TOKEN is not set, so no comment is written.'],
]);

it('reads its token from the variable tokenEnv names', function (): void {
    $environment = bitbucketPullRequest(['MUTATION_GATE_BITBUCKET_TOKEN' => '', 'GATE_COMMENTS' => 'bb-other']);
    $cloud = new Cloud()->answering(bitbucketPage(1), 200, '{"values": []}')->answering(BITBUCKET_COMMENTS, 201, bitbucketWritten(2));

    expect(PullRequestComment::configured(Configs::options('{"tokenEnv": "GATE_COMMENTS"}'), $environment, $cloud->exchange())->report(Verdicts::passing()))
        ->toBeInstanceOf(Written::class)
        ->and($cloud->requests[0]['headers']['authorization'] ?? '')->toBe('Bearer bb-other')
        ->and(PullRequestComment::configured(Configs::options('{}'), $environment, new Cloud()->exchange())->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('MUTATION_GATE_BITBUCKET_TOKEN is not set, so no comment is written.'));
});

it('says why, naming Bitbucket by its origin alone and never the token, where Bitbucket refuses', function (string $refused, int $status): void {
    $cloud = new Cloud()
        ->answering(bitbucketPage(1), 200, (string) json_encode(['values' => [bitbucketComment(5, bitbucketMarked())]]))
        ->answering(sprintf('%s/5', BITBUCKET_COMMENTS), 200, bitbucketWritten(5))
        ->answering($refused, $status, '{"error": {"message": "no"}}');
    $answer = PullRequestComment::inEnvironment(bitbucketPullRequest(), $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')
        ->report(Verdicts::passing());

    expect($answer)->toEqual(NotWritten::because(sprintf(
        'The pull request comment could not be written (https://api.bitbucket.org answered %d: {"error": {"message": "no"}}).',
        $status,
    )));
})->with([
    'the comments' => [fn(): string => bitbucketPage(1), 401],
    'the update' => [fn(): string => sprintf('%s/5', BITBUCKET_COMMENTS), 500],
]);

it('leaves its comment, and its planned state, for deliver on a pull request, token or not', function (string $token): void {
    $cloud = new Cloud();
    $comment = PullRequestComment::inEnvironment(
        bitbucketPullRequest(['MUTATION_GATE_BITBUCKET_TOKEN' => $token]),
        $cloud->exchange(),
        'MUTATION_GATE_BITBUCKET_TOKEN',
    );

    expect($comment->deferred(Verdicts::failing(), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(Markdown::comment(Verdicts::failing())))
        ->and($comment->plannedLater(ShardedPlan::planned(2), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(PlannedMarkdown::comment(ShardedPlan::planned(2), BITBUCKET_PIPELINE)))
        ->and($comment->recheckedLater(Rechecks::mixed(), Delivery::none()))
        ->toEqual(Delivery::none()->withCommentOverPlanned(RecheckedMarkdown::comment(Rechecks::mixed(), BITBUCKET_PIPELINE)))
        ->and($cloud->requests)->toBe([]);
})->with(['with a token' => ['bb-secret'], 'with none' => ['']]);

it('leaves no comment for deliver on a step that is no pull request\'s', function (): void {
    $comment = PullRequestComment::inEnvironment(bitbucketPullRequest(['BITBUCKET_PR_ID' => '']), new Cloud()->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN');

    expect($comment->deferred(Verdicts::failing(), Delivery::none()))->toEqual(Delivery::none())
        ->and($comment->plannedLater(ShardedPlan::planned(1), Delivery::none()))->toEqual(Delivery::none())
        ->and($comment->recheckedLater(Rechecks::mixed(), Delivery::none()))->toEqual(Delivery::none())
        ->and($comment->write('text'))->toEqual(NotWritten::because(CommentStates::NOT_A_PULL_REQUEST))
        ->and($comment->writeOverPlanned('text'))->toEqual(NotWritten::because(CommentStates::NOT_A_PULL_REQUEST));
});

it('reads the comments of a workspace and a repository each encoded where it holds a reserved character', function (): void {
    $cloud = new Cloud();
    $step = bitbucketPullRequest(['BITBUCKET_WORKSPACE' => 'ac?me', 'BITBUCKET_REPO_SLUG' => 'sh/op']);
    PullRequestComment::inEnvironment($step, $cloud->exchange(), 'MUTATION_GATE_BITBUCKET_TOKEN')->report(Verdicts::failing());

    expect($cloud->requests[0]['url'])
        ->toBe('https://api.bitbucket.org/2.0/repositories/ac%3Fme/sh%2Fop/pullrequests/7/comments?pagelen=50&page=1');
});
