<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Core\Comment\Mode;
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
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Owners;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\HttpClient\HttpClient;
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

$report = static fn(int $id): JsonMockResponse => new JsonMockResponse(['total_count' => 1, 'artifacts' => [['id' => $id, 'expired' => false]]]);

it('posts a new comment where the token\'s identity has none, linking the run\'s HTML report', function () use ($environment, $event, $comment, $report): void {
    $post = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-9']);
    $artifacts = $report(42);
    $requests = [
        new JsonMockResponse(['login' => 'gate-bot']),
        new JsonMockResponse([$comment(1, 'someone', 'Looks good'), $comment(2, 'someone', Markdown::MARKER)]),
        $artifacts,
        $post,
    ];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), '', CommentSettings::standard())->report(Verdicts::failing());
    $body = Decoded::at(is_string($post->getRequestOptions()['body']) ? $post->getRequestOptions()['body'] : '', 'body');

    expect($answer)->toEqual(Written::to('https://github.example/octo/gate/pull/12#issuecomment-9'))
        ->and($post->getRequestMethod())->toBe('POST')
        ->and($post->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/12/comments')
        ->and($artifacts->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/actions/runs/7/artifacts?name=mutation-gate-report.html')
        ->and($body)->toBe(str_replace(
            Markdown::REPORT_SLOT,
            '[The HTML report](https://github.example/octo/gate/actions/runs/7/artifacts/42) of [this run](https://github.example/octo/gate/actions/runs/7).',
            Markdown::comment(Verdicts::failing(), CommentShows::of()),
        ))
        ->and($body)->toEndWith("[this run](https://github.example/octo/gate/actions/runs/7).\n\n</details>\n");
});

it('updates its own comment in place, passing runs included, reading every page of comments', function () use ($environment, $event, $comment, $report): void {
    $firstPage = array_map(static fn(int $id): array => $comment($id, 'someone', 'text'), range(1, 100));
    $second = new JsonMockResponse([$comment(101, 'github-actions[bot]', sprintf("%s\nold", Markdown::MARKER))]);
    $patch = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-101']);
    $requests = [new MockResponse('{"message": "Resource not accessible by integration"}', ['http_code' => 403]), new JsonMockResponse($firstPage), $second, $report(43), $patch];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), '', CommentSettings::standard())->report(Verdicts::passing());

    expect($answer)->toEqual(Written::to('https://github.example/octo/gate/pull/12#issuecomment-101'))
        ->and(Decoded::at(is_string($patch->getRequestOptions()['body']) ? $patch->getRequestOptions()['body'] : '', 'body'))
        ->toContain('[The HTML report](https://github.example/octo/gate/actions/runs/7/artifacts/43)')
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

    PullRequestComment::inRun($environment, $event(), $client, '', CommentSettings::standard())->report(Verdicts::failing());

    expect($written)->toBe([$method]);
})->with([
    'its comment on the thirtieth page, which it updates' => [30, 'PATCH'],
    'its comment on the thirty-first page, past which it posts a new one' => [31, 'POST'],
]);

it('finds its comment by the identity it is given, asking GitHub nothing about the token', function () use ($environment, $event, $comment, $report): void {
    $patch = new JsonMockResponse(['html_url' => 'u']);
    $requests = [new JsonMockResponse([$comment(5, 'gate-bot', Markdown::MARKER), $comment(6, 'other-bot', Markdown::MARKER)]), $report(1), $patch];
    PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), 'other-bot', CommentSettings::standard())->report(Verdicts::passing());

    expect($patch->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/comments/6');
});

it('posts each run\'s own comment under comment.mode, leaving the comments before it, or hiding them', function (Mode $mode, int $hidden) use ($environment, $event, $comment, $report): void {
    $run = 'https://github.example/octo/gate/actions/runs/7';
    $post = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-9']);
    $earlier = [
        $comment(1, 'gate-bot', sprintf("%s\nearlier\n\n<!-- mutation-gate run: https://github.example/octo/gate/actions/runs/6 -->\n", Markdown::MARKER)),
        $comment(2, 'someone', sprintf("%s\nnot the gate's", Markdown::MARKER)),
        $comment(3, 'gate-bot', 'no marker'),
    ];
    $known = new JsonMockResponse(['data' => ['nodes' => [['id' => 'one', 'isMinimized' => false]]]]);
    $hide = new JsonMockResponse(['data' => ['minimizeComment' => ['minimizedComment' => ['isMinimized' => true]]]]);
    $requests = [new JsonMockResponse($earlier), new JsonMockResponse($earlier), $report(42), $post, $known, $hide];
    $client = new MockHttpClient($requests);
    $settings = CommentSettings::of(CommentShows::of(), CommentWrites::of(mode: $mode));
    $answer = PullRequestComment::inRun($environment, $event(), $client, 'gate-bot', $settings)->report(Verdicts::failing());
    $body = Decoded::at(is_string($post->getRequestOptions()['body']) ? $post->getRequestOptions()['body'] : '', 'body');

    expect($answer)->toEqual(Written::to('https://github.example/octo/gate/pull/12#issuecomment-9'))
        ->and($post->getRequestMethod())->toBe('POST')
        ->and($body)->toEndWith(sprintf("\n\n<!-- mutation-gate run: %s -->\n", $run))
        ->and($client->getRequestsCount())->toBe(4 + $hidden);
})->with([
    'a new comment' => [fn(): Mode => Mode::New, 0],
    'a new comment that hides the one before' => [fn(): Mode => Mode::NewHidingOld, 2],
]);

it('hides only the comments of other runs this author wrote holding the gate\'s marker, through every page it reads', function (int $pages) use ($environment, $event, $comment): void {
    $run = 'https://github.example/octo/gate/actions/runs/7';
    $earlier = static fn(int $id): array => [
        ...$comment($id, 'gate-bot', sprintf("%s\n<!-- mutation-gate run: https://github.example/octo/gate/actions/runs/%d -->\n", Markdown::MARKER, $id)),
        'node_id' => sprintf('node-%d', $id),
    ];
    $noise = static fn(int $id): array => [...$comment($id, 'someone', 'text'), 'node_id' => sprintf('noise-%d', $id)];
    $full = array_map($noise, range(4, 100));
    $page = static fn(int $number): array => match (true) {
        $number === 1 => [
            $earlier(1),
            [...$comment(2, 'someone', sprintf("%s\nnot the gate's", Markdown::MARKER)), 'node_id' => 'theirs'],
            [...$comment(3, 'gate-bot', 'no marker'), 'node_id' => 'unmarked'],
            ...$full,
        ],
        $number === $pages => [
            $earlier(200),
            [...$comment(201, 'gate-bot', sprintf("%s\n%s", Markdown::MARKER, sprintf(CommentWrite::RUN, 'elsewhere'))), 'node_id' => 'node-201'],
            ...array_map($noise, range(4, 50)),
        ],
        default => array_map($noise, range(1, 100)),
    };
    $asked = [];
    $queries = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$asked, &$queries, $page): JsonMockResponse {
        $number = preg_match('/[?&]page=(\d+)/', $url, $found) === 1 ? (int) $found[1] : 0;

        return match (true) {
            str_ends_with($url, '/graphql') => (static function () use (&$queries, $options): JsonMockResponse {
                $queries[] = Decoded::at(is_string($options['body'] ?? null) ? $options['body'] : '', 'query');

                return new JsonMockResponse(['data' => ['nodes' => []]]);
            })(),
            $method === 'POST' => new JsonMockResponse(['html_url' => 'written']),
            $number > 0 => (static function () use (&$asked, $number, $page): JsonMockResponse {
                $asked[] = $number;

                return new JsonMockResponse($page($number));
            })(),
            default => new JsonMockResponse(['total_count' => 0, 'artifacts' => []]),
        };
    });
    $thread = PullRequestComment::inRun($environment, $event(), $client, 'gate-bot', CommentSettings::of(CommentShows::of(), CommentWrites::of(mode: Mode::NewHidingOld)));

    expect($thread->writeAs('text', CommentWrite::of(Mode::NewHidingOld, $run)))->toEqual(Written::to('written'))
        ->and($queries)->toBe(['query { nodes(ids: ["node-1", "node-200", "node-201"]) { ... on IssueComment { id isMinimized } } }'])
        ->and(array_values(array_unique($asked)))->toBe(range(1, $pages));
})->with([
    'the first page and the next' => [2],
    'the last of the thirty pages it reads' => [30],
]);

it('reads thirty pages of comments of other runs, and no more', function () use ($environment, $event, $comment): void {
    $asked = [];
    $client = new MockHttpClient(static function (string $method, string $url) use (&$asked, $comment): JsonMockResponse {
        if (preg_match('/[?&]page=(\d+)/', $url, $found) === 1) {
            $asked[] = (int) $found[1];

            return new JsonMockResponse(array_map(static fn(int $id): array => $comment($id, 'someone', 'text'), range(1, 100)));
        }

        return $method === 'POST' ? new JsonMockResponse(['html_url' => 'written']) : new JsonMockResponse(['total_count' => 0, 'artifacts' => []]);
    });
    $thread = PullRequestComment::inRun($environment, $event(), $client, 'gate-bot', CommentSettings::standard());

    expect($thread->writeAs('text', CommentWrite::of(Mode::New, 'run')))->toEqual(Written::to('written'))
        ->and($asked)->toBe([...range(1, 30), ...range(1, 30)]);
});

it('updates only where the gate has commented before, an earlier run\'s comment being one', function (string $author, bool $written) use ($environment, $event, $comment): void {
    $held = [$comment(1, $author, Markdown::MARKER)];
    $post = new JsonMockResponse(['html_url' => 'written']);
    $thread = PullRequestComment::inRun(
        $environment,
        $event(),
        new MockHttpClient([new JsonMockResponse($held), new JsonMockResponse($held), $post]),
        'gate-bot',
        CommentSettings::standard(),
    );

    expect($thread->writeAs('text', CommentWrite::of(Mode::New, 'run')->existingOnly()))
        ->toEqual($written ? Written::to('written') : NotWritten::because(CommentStates::NOT_THERE));
})->with([
    'an earlier run\'s comment' => ['gate-bot', true],
    'none of the gate\'s' => ['someone', false],
]);

it('reads a draft from the event, so comment.drafts can keep it quiet, and takes a draft it cannot read as none', function (string $draft, bool $left) use ($environment, $report): void {
    $event = sprintf('{"pull_request": {"number": 12, "draft": %s}}', $draft);
    $settings = CommentSettings::of(CommentShows::of(), CommentWrites::of(toggles: CommentToggles::off(Toggle::Drafts)));
    $post = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-9']);
    $client = new MockHttpClient([new JsonMockResponse([]), $report(1), $post]);
    $answer = PullRequestComment::inRun($environment, $event, $client, 'gate-bot', $settings)->report(Verdicts::failing());

    expect($answer)->toEqual($left
        ? NotWritten::because('comment.drafts is false, so this run writes no comment.')
        : Written::to('https://github.example/octo/gate/pull/12#issuecomment-9'));
})->with([
    'a draft' => ['true', true],
    'no draft' => ['false', false],
    'a draft it cannot read' => ['"yes"', false],
]);

it('writes its planned state into the same comment the verdict later replaces', function () use ($environment, $event, $comment): void {
    $patch = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-5']);
    $requests = [new JsonMockResponse([$comment(5, 'gate-bot', Markdown::MARKER)]), $patch];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), 'gate-bot', CommentSettings::standard())
        ->planned(ShardedPlan::planned(2));

    expect($answer)->toEqual(Written::to('https://github.example/octo/gate/pull/12#issuecomment-5'))
        ->and($patch->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/comments/5')
        ->and(Decoded::at(is_string($patch->getRequestOptions()['body']) ? $patch->getRequestOptions()['body'] : '', 'body'))
        ->toBe(PlannedMarkdown::comment(ShardedPlan::planned(2), 'https://github.example/octo/gate/actions/runs/7'));
});

it('writes the re-checked survivors over its planned state alone, so a verdict already written stays', function (
    string $body,
    bool $written,
) use ($environment, $event, $comment): void {
    $patch = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-5']);
    $requests = [new JsonMockResponse([$comment(5, 'gate-bot', $body)]), $patch];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), 'gate-bot', CommentSettings::standard())
        ->rechecked(Rechecks::mixed());

    expect($answer)->toEqual($written
        ? Written::to('https://github.example/octo/gate/pull/12#issuecomment-5')
        : NotWritten::because(
            CommentStates::NOT_PLANNED,
        ));

    if ($written) {
        expect($patch->getRequestMethod())->toBe('PATCH')
            ->and($patch->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/comments/5')
            ->and(Decoded::at(is_string($patch->getRequestOptions()['body']) ? $patch->getRequestOptions()['body'] : '', 'body'))
            ->toBe(RecheckedMarkdown::comment(Rechecks::mixed(), 'https://github.example/octo/gate/actions/runs/7', CommentShows::of()));
    }
})->with([
    'planned' => [fn(): string => PlannedMarkdown::comment(ShardedPlan::planned(2), ''), true],
    'the verdict' => [fn(): string => Markdown::comment(Verdicts::failing(), CommentShows::of()), false],
    'the heading quoted inside a line' => [fn(): string => sprintf("%s\nnot %s here", Markdown::MARKER, PlannedMarkdown::HEADING), false],
]);

it('writes no re-checked survivors where the pull request has no comment of its own yet', function () use ($environment, $event, $comment): void {
    $requests = [new JsonMockResponse([$comment(5, 'someone', PlannedMarkdown::comment(ShardedPlan::planned(2), ''))])];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), 'gate-bot', CommentSettings::standard())
        ->rechecked(Rechecks::mixed());

    expect($answer)->toEqual(NotWritten::because(
        CommentStates::NOT_PLANNED,
    ));
});

it('writes no planned state, and says why, where the run cannot comment', function () use ($event): void {
    $answer = PullRequestComment::inRun(['GITHUB_EVENT_NAME' => 'push'], $event(), new MockHttpClient([]), '', CommentSettings::standard())
        ->planned(ShardedPlan::planned(1));

    expect($answer)->toEqual(NotWritten::because('This run is not for a pull request, so there is no comment to write.'));
});

it('says why, and fails nothing, where GitHub refuses the comment', function () use ($environment, $event, $report): void {
    $requests = [
        new JsonMockResponse(['login' => 'gate-bot']),
        new JsonMockResponse([]),
        $report(1),
        new MockResponse('{"message": "Resource not accessible by integration"}', ['http_code' => 403]),
    ];
    $answer = PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), '', CommentSettings::standard())->report(Verdicts::failing());

    expect($answer)->toBeInstanceOf(NotWritten::class)
        ->and($answer instanceof NotWritten ? $answer->why() : '')->toStartWith('The pull request comment could not be written (')
        ->and($answer instanceof NotWritten ? $answer->why() : '')->toContain('403')
        ->and($answer instanceof NotWritten ? $answer->why() : '')->toEndWith('); the step summary carries it.');
});

it('writes no comment, and says why, where the run cannot comment', function (string $name, string $token, string $event, string $why): void {
    $environment = ['GITHUB_EVENT_NAME' => $name, 'GITHUB_TOKEN' => $token];
    $answer = PullRequestComment::inRun($environment, $event, new MockHttpClient([]), '', CommentSettings::standard())->report(Verdicts::failing());

    expect($answer)->toEqual(NotWritten::because($why));
})->with([
    'a push' => ['push', 'secret', fn(): string => $event(), 'This run is not for a pull request, so there is no comment to write.'],
    'no pull request in the event' => ['pull_request', 'secret', '{}', 'This run is not for a pull request, so there is no comment to write.'],
    'no token' => ['pull_request', '', fn(): string => $event(), 'GITHUB_TOKEN is not set, so no comment is written; the step summary carries it.'],
    'a fork' => ['pull_request', 'secret', fn(): string => $event('someone/gate'), 'A fork\'s pull request gets a read-only token, so no comment is written; the step summary carries it.'],
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

it('asks GitHub who it is where its options name no identity', function (): void {
    $run = ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_EVENT_PATH' => null];
    $read = Environment::during(
        $run,
        static fn(): PullRequestComment => PullRequestComment::fromOptions(Configs::options('{"identity": 3}')),
    );
    $asking = Environment::during(
        $run,
        static fn(): PullRequestComment => PullRequestComment::fromEnvironment(getenv(), HttpClient::create(), '', CommentSettings::standard()),
    );

    expect($read)->toEqual($asking);
});

it('reads no event where the environment names no event file', function (): void {
    $comment = PullRequestComment::fromEnvironment(['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => 'secret'], new MockHttpClient([]), '', CommentSettings::standard());

    expect($comment->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('This run is not for a pull request, so there is no comment to write.'));
});

it('asks GitHub itself where no API is named', function () use ($event, $report): void {
    $post = new JsonMockResponse(['html_url' => 'u']);
    $environment = ['GITHUB_EVENT_NAME' => 'pull_request_target', 'GITHUB_TOKEN' => 'secret', 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_RUN_ID' => '7'];
    PullRequestComment::inRun($environment, $event(), new MockHttpClient([new JsonMockResponse([]), $report(5), $post]), 'gate-bot', CommentSettings::standard())->report(Verdicts::failing());

    expect($post->getRequestUrl())->toBe('https://api.github.com/repos/octo/gate/issues/12/comments')
        ->and(Decoded::at(is_string($post->getRequestOptions()['body']) ? $post->getRequestOptions()['body'] : '', 'body'))
        ->toContain('[The HTML report](https://github.com/octo/gate/actions/runs/7/artifacts/5) of [this run](https://github.com/octo/gate/actions/runs/7).');
});

it('comments where the event does not say which repositories the pull request joins', function () use ($environment, $report): void {
    $post = new JsonMockResponse(['html_url' => 'u']);
    $answer = PullRequestComment::inRun($environment, '{"pull_request": {"number": 12}}', new MockHttpClient([new JsonMockResponse([]), $report(1), $post]), 'gate-bot', CommentSettings::standard())
        ->report(Verdicts::failing());

    expect($answer)->toEqual(Written::to('u'))
        ->and($post->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/issues/12/comments');
});

it('leaves its comment, and its planned state, for deliver on a pull request, token or not, fork or not', function (string $token, string $head) use ($environment, $event): void {
    $client = new MockHttpClient([]);
    $comment = PullRequestComment::inRun([...$environment, 'GITHUB_TOKEN' => $token], $event($head), $client, '', CommentSettings::standard());
    $run = 'https://github.example/octo/gate/actions/runs/7';

    expect($comment->deferred(Verdicts::failing(), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(Markdown::comment(Verdicts::failing(), CommentShows::of()), CommentWrite::sticky()))
        ->and($comment->plannedLater(ShardedPlan::planned(2), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(PlannedMarkdown::comment(ShardedPlan::planned(2), $run), CommentWrite::sticky()))
        ->and($comment->recheckedLater(Rechecks::mixed(), Delivery::none()))
        ->toEqual(Delivery::none()->withComment(RecheckedMarkdown::comment(Rechecks::mixed(), $run, CommentShows::of()), CommentWrite::sticky()->overPlanned()))
        ->and($client->getRequestsCount())->toBe(0);
})->with([
    'with a token' => ['secret', 'octo/gate'],
    'with none' => ['', 'octo/gate'],
    'from a fork' => ['', 'someone/gate'],
]);

it('leaves no comment for deliver on a run that is no pull request\'s', function (string $name, string $event): void {
    $comment = PullRequestComment::inRun(['GITHUB_EVENT_NAME' => $name], $event, new MockHttpClient([]), '', CommentSettings::standard());

    expect($comment->deferred(Verdicts::failing(), Delivery::none()->withComment('kept', CommentWrite::sticky())))->toEqual(Delivery::none()->withComment('kept', CommentWrite::sticky()))
        ->and($comment->plannedLater(ShardedPlan::planned(1), Delivery::none()))->toEqual(Delivery::none())
        ->and($comment->recheckedLater(Rechecks::mixed(), Delivery::none()))->toEqual(Delivery::none());
})->with([
    'a push' => ['push', fn(): string => $event()],
    'no pull request in the event' => ['pull_request', '{}'],
]);

it('mentions each owner group\'s users and teams the first time, as a token that can mention a team', function () use ($environment, $event, $comment, $report): void {
    $patch = new JsonMockResponse(['html_url' => 'u']);
    $requests = [new JsonMockResponse([$comment(5, 'gate-bot', Markdown::MARKER)]), $report(1), $patch];
    PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), 'gate-bot', CommentSettings::standard())->report(Owners::mentioned());
    $body = Decoded::at(is_string($patch->getRequestOptions()['body']) ? $patch->getRequestOptions()['body'] : '', 'body');

    expect($body)->toContain("\n#### @acme/payments @jo (1)\n")
        ->and($body)->toEndWith("\n<!-- mutation-gate: mentioned @acme/payments @jo -->\n");
});

it('mentions no team as GitHub Actions\' own bot, and mentions its users', function () use ($environment, $event, $comment, $report): void {
    $patch = new JsonMockResponse(['html_url' => 'u']);
    $requests = [
        new MockResponse('{"message": "Resource not accessible by integration"}', ['http_code' => 403]),
        new JsonMockResponse([$comment(5, 'github-actions[bot]', Markdown::MARKER)]),
        $report(1),
        $patch,
    ];
    PullRequestComment::inRun($environment, $event(), new MockHttpClient($requests), '', CommentSettings::standard())->report(Owners::mentioned());
    $body = Decoded::at(is_string($patch->getRequestOptions()['body']) ? $patch->getRequestOptions()['body'] : '', 'body');

    expect($body)->toContain("\n#### &#64;acme/payments @jo (1)\n")
        ->and($body)->toEndWith("\n<!-- mutation-gate: mentioned @jo -->\n");
});

it('names owners its earlier updates mentioned without a mention, and keeps their record through its planned state', function () use ($environment, $event, $comment, $report): void {
    $before = sprintf("%s\nold\n<!-- mutation-gate: mentioned @acme/payments @jo -->\n", Markdown::MARKER);
    $planned = new JsonMockResponse(['html_url' => 'u']);
    $verdict = new JsonMockResponse(['html_url' => 'u']);
    $plannedBody = sprintf("%s<!-- mutation-gate: mentioned @acme/payments @jo -->\n", PlannedMarkdown::comment(ShardedPlan::planned(2), 'https://github.example/octo/gate/actions/runs/7'));
    $client = new MockHttpClient([
        new JsonMockResponse([$comment(5, 'gate-bot', $before)]),
        $planned,
        new JsonMockResponse([$comment(5, 'gate-bot', $plannedBody)]),
        $report(1),
        $verdict,
    ]);
    $writer = PullRequestComment::inRun($environment, $event(), $client, 'gate-bot', CommentSettings::standard());
    $writer->planned(ShardedPlan::planned(2));
    $writer->report(Owners::mentioned());
    $body = Decoded::at(is_string($verdict->getRequestOptions()['body']) ? $verdict->getRequestOptions()['body'] : '', 'body');

    expect(Decoded::at(is_string($planned->getRequestOptions()['body']) ? $planned->getRequestOptions()['body'] : '', 'body'))->toBe($plannedBody)
        ->and($body)->toContain("\n#### &#64;acme/payments &#64;jo (1)\n")
        ->and($body)->not->toContain('@jo (1)')
        ->and($body)->toEndWith("\n<!-- mutation-gate: mentioned @acme/payments @jo -->\n");
});
