<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Markdown;
use NightWorksIO\MutationGate\Adapter\GitHub\PlannedMarkdown;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

afterEach(function (): void {
    Scratch::sweep();
});

$environment = [
    'GITHUB_EVENT_NAME' => 'pull_request',
    'GITHUB_TOKEN' => 'secret',
    'GITHUB_REPOSITORY' => 'octo/gate',
    'GITHUB_API_URL' => 'https://api.github.example',
    'GITHUB_SERVER_URL' => 'https://github.example',
    'GITHUB_RUN_ID' => '7',
];

$event = static fn(string $head = 'octo/gate'): string => (string) json_encode(['pull_request' => [
    'number' => 12,
    'head' => ['repo' => ['full_name' => $head]],
    'base' => ['repo' => ['full_name' => 'octo/gate']],
]]);

$comment = static fn(int $id, string $login, string $body): array => ['id' => $id, 'user' => ['login' => $login], 'body' => $body];

it('posts a new comment where the token\'s identity has none', function () use ($environment, $event, $comment): void {
    $post = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-9']);
    $requests = [
        new JsonMockResponse(['login' => 'gate-bot']),
        new JsonMockResponse([$comment(1, 'someone', 'Looks good'), $comment(2, 'someone', Markdown::MARKER)]),
        $post,
    ];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), '')->report(Verdicts::failing());

    expect($answer)->toEqual(Written::to('https://github.example/octo/gate/pull/12#issuecomment-9'))
        ->and($post->getRequestMethod())->toBe('POST')
        ->and($post->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/12/comments')
        ->and(Decoded::at(is_string($post->getRequestOptions()['body']) ? $post->getRequestOptions()['body'] : '', 'body'))
        ->toBe(Markdown::comment(Verdicts::failing(), 'https://github.example/octo/gate/actions/runs/7'));
});

it('updates its own comment in place, passing runs included, reading every page of comments', function () use ($environment, $event, $comment): void {
    $firstPage = array_map(static fn(int $id): array => $comment($id, 'someone', 'text'), range(1, 100));
    $second = new JsonMockResponse([$comment(101, 'github-actions[bot]', sprintf("%s\nold", Markdown::MARKER))]);
    $patch = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-101']);
    $requests = [new MockResponse('{"message": "Resource not accessible by integration"}', ['http_code' => 403]), new JsonMockResponse($firstPage), $second, $patch];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), '')->report(Verdicts::passing());

    expect($answer)->toEqual(Written::to('https://github.example/octo/gate/pull/12#issuecomment-101'))
        ->and($second->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/12/comments?per_page=100&page=2')
        ->and($patch->getRequestMethod())->toBe('PATCH')
        ->and($patch->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/comments/101');
});

it('reads thirty full pages of comments for its own, and no more', function (int $page, string $method) use ($environment, $event, $comment): void {
    $written = [];
    $client = new MockHttpClient(static function (string $verb, string $url) use ($page, $comment, &$written): JsonMockResponse {
        $asked = preg_match('/[?&]page=(\d+)/', $url, $found) === 1 ? (int) $found[1] : 0;
        $comments = array_map(static fn(int $id): array => $comment($id, 'someone', 'text'), range(1, 100));
        $comments[0] = $asked === $page ? $comment(9, 'gate-bot', Markdown::MARKER) : $comments[0];
        $written = $verb === 'GET' ? $written : [...$written, $verb];

        return match (true) {
            $verb !== 'GET' => new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-9']),
            str_ends_with($url, '/user') => new JsonMockResponse(['login' => 'gate-bot']),
            default => new JsonMockResponse($comments),
        };
    });

    PullRequestComment::inRun($environment, $event(), $client, '')->report(Verdicts::failing());

    expect($written)->toBe([$method]);
})->with([
    'its comment on the thirtieth page, which it updates' => [30, 'PATCH'],
    'its comment on the thirty-first page, past which it posts a new one' => [31, 'POST'],
]);

it('finds its comment by the identity it is given, asking GitHub nothing about the token', function () use ($environment, $event, $comment): void {
    $patch = new JsonMockResponse(['html_url' => 'u']);
    $requests = [new JsonMockResponse([$comment(5, 'gate-bot', Markdown::MARKER), $comment(6, 'other-bot', Markdown::MARKER)]), $patch];
    PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), 'other-bot')->report(Verdicts::passing());

    expect($patch->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/comments/6');
});

it('writes its planned state into the same comment the verdict later replaces', function () use ($environment, $event, $comment): void {
    $patch = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-5']);
    $requests = [new JsonMockResponse([$comment(5, 'gate-bot', Markdown::MARKER)]), $patch];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), 'gate-bot')
        ->planned(ShardedPlan::planned(2));

    expect($answer)->toEqual(Written::to('https://github.example/octo/gate/pull/12#issuecomment-5'))
        ->and($patch->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/comments/5')
        ->and(Decoded::at(is_string($patch->getRequestOptions()['body']) ? $patch->getRequestOptions()['body'] : '', 'body'))
        ->toBe(PlannedMarkdown::comment(ShardedPlan::planned(2), 'https://github.example/octo/gate/actions/runs/7'));
});

it('writes no planned state, and says why, where the run cannot comment', function () use ($event): void {
    $answer = PullRequestComment::inRun(['GITHUB_EVENT_NAME' => 'push'], $event(), new MockHttpClient([]), '')
        ->planned(ShardedPlan::planned(1));

    expect($answer)->toEqual(NotWritten::because('This run is not for a pull request, so there is no comment to write.'));
});

it('says why, and fails nothing, where GitHub refuses the comment', function () use ($environment, $event): void {
    $requests = [
        new JsonMockResponse(['login' => 'gate-bot']),
        new JsonMockResponse([]),
        new MockResponse('{"message": "Resource not accessible by integration"}', ['http_code' => 403]),
    ];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), '')->report(Verdicts::passing());

    expect($answer)->toBeInstanceOf(NotWritten::class)
        ->and($answer instanceof NotWritten ? $answer->why() : '')->toStartWith('The pull request comment could not be written (')
        ->and($answer instanceof NotWritten ? $answer->why() : '')->toContain('403')
        ->and($answer instanceof NotWritten ? $answer->why() : '')->toEndWith('); the step summary carries it.');
});

it('writes no comment, and says why, where the run cannot comment', function (string $name, string $token, string $event, string $why): void {
    $environment = ['GITHUB_EVENT_NAME' => $name, 'GITHUB_TOKEN' => $token];
    $answer = PullRequestComment::inRun($environment, $event, new MockHttpClient([]), '')->report(Verdicts::failing());

    expect($answer)->toEqual(NotWritten::because($why));
})->with([
    'a push' => ['push', 'secret', $event(), 'This run is not for a pull request, so there is no comment to write.'],
    'no pull request in the event' => ['pull_request', 'secret', '{}', 'This run is not for a pull request, so there is no comment to write.'],
    'no token' => ['pull_request', '', $event(), 'GITHUB_TOKEN is not set, so no comment is written; the step summary carries it.'],
    'a fork' => ['pull_request', 'secret', $event('someone/gate'), 'A fork\'s pull request gets a read-only token, so no comment is written; the step summary carries it.'],
]);

it('reads its run from the environment and the event file, and its identity from its options', function () use ($event): void {
    $root = Scratch::directory();
    Scratch::write($root, 'event.json', $event());
    $run = ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => null, 'GITHUB_REPOSITORY' => 'octo/gate'];
    $noToken = Environment::during(
        [...$run, 'GITHUB_EVENT_PATH' => sprintf('%s/event.json', $root)],
        static fn(): PullRequestComment => PullRequestComment::fromOptions(Configs::options('{"identity": "gate-bot"}')),
    );
    $noEvent = Environment::during(
        [...$run, 'GITHUB_EVENT_PATH' => sprintf('%s/none.json', $root)],
        static fn(): PullRequestComment => PullRequestComment::fromOptions(Configs::options('{"identity": 3}')),
    );

    expect($noToken->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('GITHUB_TOKEN is not set, so no comment is written; the step summary carries it.'))
        ->and($noEvent->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('This run is not for a pull request, so there is no comment to write.'));
});

it('asks GitHub itself where no API is named', function () use ($event): void {
    $post = new JsonMockResponse(['html_url' => 'u']);
    $environment = ['GITHUB_EVENT_NAME' => 'pull_request_target', 'GITHUB_TOKEN' => 'secret', 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_RUN_ID' => '7'];
    PullRequestComment::inRun($environment, $event(), new MockHttpClient([new JsonMockResponse([]), $post]), 'gate-bot')->report(Verdicts::passing());

    expect($post->getRequestUrl())->toBe('https://api.github.com/repos/octo/gate/issues/12/comments')
        ->and(Decoded::at(is_string($post->getRequestOptions()['body']) ? $post->getRequestOptions()['body'] : '', 'body'))
        ->toContain('[The run](https://github.com/octo/gate/actions/runs/7)');
});

it('comments where the event does not say which repositories the pull request joins', function () use ($environment): void {
    $post = new JsonMockResponse(['html_url' => 'u']);
    $answer = PullRequestComment::inRun($environment, '{"pull_request": {"number": 12}}', new MockHttpClient([new JsonMockResponse([]), $post]), 'gate-bot')
        ->report(Verdicts::passing());

    expect($answer)->toEqual(Written::to('u'))
        ->and($post->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/12/comments');
});

it('leaves its comment, and its planned state, for deliver on a pull request, token or not, fork or not', function (string $token, string $head) use ($environment, $event): void {
    $client = new MockHttpClient([]);
    $comment = PullRequestComment::inRun([...$environment, 'GITHUB_TOKEN' => $token], $event($head), $client, '');
    $run = 'https://github.example/octo/gate/actions/runs/7';

    expect($comment->deferred(Verdicts::failing(), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(Markdown::comment(Verdicts::failing(), $run)))
        ->and($comment->plannedLater(ShardedPlan::planned(2), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(PlannedMarkdown::comment(ShardedPlan::planned(2), $run)))
        ->and($client->getRequestsCount())->toBe(0);
})->with([
    'with a token' => ['secret', 'octo/gate'],
    'with none' => ['', 'octo/gate'],
    'from a fork' => ['', 'someone/gate'],
]);

it('leaves no comment for deliver on a run that is no pull request\'s', function (string $name, string $event): void {
    $comment = PullRequestComment::inRun(['GITHUB_EVENT_NAME' => $name], $event, new MockHttpClient([]), '');

    expect($comment->deferred(Verdicts::failing(), Delivery::none()->withComment('kept')))->toEqual(Delivery::none()->withComment('kept'))
        ->and($comment->plannedLater(ShardedPlan::planned(1), Delivery::none()))->toEqual(Delivery::none());
})->with([
    'a push' => ['push', $event()],
    'no pull request in the event' => ['pull_request', '{}'],
]);
