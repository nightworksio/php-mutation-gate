<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\AlertReporter;
use NightWorksIO\MutationGate\Adapter\Alert\Channel;
use NightWorksIO\MutationGate\Adapter\Alert\Delivery;
use NightWorksIO\MutationGate\Adapter\Alert\Pause;
use NightWorksIO\MutationGate\Core\Alert\Alerts;
use NightWorksIO\MutationGate\Core\Alert\SlackMessage;
use NightWorksIO\MutationGate\Core\Alert\WebhookPayload;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$github = [
    'CI' => 'true',
    'GITHUB_ACTIONS' => 'true',
    'GITHUB_REPOSITORY' => 'octo/gate',
    'GITHUB_REF' => 'refs/heads/main',
    'GITHUB_SHA' => '5eeca8f0d2b1c4a7e9f3a6b8c0d2e4f6a8b0c2d4',
    'GITHUB_SERVER_URL' => 'https://github.example',
    'GITHUB_RUN_ID' => '7',
];
/**
 * The reporter for this channel in this environment, posting to these answers, with these options.
 *
 * @param list<MockResponse> $answers
 */
function alertReporter(Channel $channel, Variables $environment, array $answers, string $options = '{}'): AlertReporter|Invalid
{
    return AlertReporter::inEnvironment(
        $channel,
        Configs::options($options),
        $environment,
        Delivery::over(new MockHttpClient($answers), new StoppedClock('2026-09-30T12:00:00Z'), Pause::for(...)),
        new StoppedClock('2026-09-30T12:00:00Z'),
    );
}

/** The headers a mock answer was requested with, one to a line. */
function headersOf(MockResponse $answer): string
{
    $headers = $answer->getRequestOptions()['headers'];

    return is_array($headers) ? implode("\n", array_filter($headers, is_string(...))) : '';
}

$reported = (static fn(AlertReporter|Invalid $reporter, Verdict $verdict): Written|NotWritten => $reporter instanceof AlertReporter ? $reporter->report($verdict) : NotWritten::because('invalid'));

it('posts each alert to the URL its variable holds, as the channel writes it', function () use ($github, $reported): void {
    $first = new MockResponse('ok');
    $second = new MockResponse('ok');
    $verdict = Verdicts::passing()->withAccount(Previous::run('failed', floor: 90.0));
    $alerts = [...Alerts::of($verdict)];
    $sent = $reported(alertReporter(Channel::Slack, Variables::of([...$github, 'MUTATION_GATE_SLACK_URL' => 'https://hooks.example/slack']), [$first, $second]), $verdict);

    expect($sent)->toEqual(Written::to('Slack'))
        ->and($first->getRequestUrl())->toBe('https://hooks.example/slack')
        ->and($first->getRequestOptions()['body'])->toBe(SlackMessage::json($alerts[0], Previous::ci()))
        ->and($second->getRequestOptions()['body'])->toBe(SlackMessage::json($alerts[1], Previous::ci()))
        ->and(headersOf($first))->toContain('Content-Type: application/json')
        ->and(headersOf($first))->not->toContain('X-Mutation-Gate-Signature');
});

it('reads the URL and the secret from the variables its options name, and signs the webhook\'s body', function () use ($github, $reported): void {
    $answer = new MockResponse('ok');
    $verdict = Verdicts::failing()->withAccount(Previous::run('passed'));
    $environment = Variables::of([...$github, 'HOOK' => 'https://hooks.example/mine', 'KEY' => 'sesame']);
    $sent = $reported(alertReporter(Channel::Webhook, $environment, [$answer], '{"urlEnv": "HOOK", "secretEnv": "KEY"}'), $verdict);
    $body = WebhookPayload::json([...Alerts::of($verdict)][0], Previous::ci());

    expect($sent)->toEqual(Written::to('the webhook'))
        ->and($answer->getRequestUrl())->toBe('https://hooks.example/mine')
        ->and($answer->getRequestOptions()['body'])->toBe($body)
        ->and(headersOf($answer))->toContain(sprintf(
            'X-Mutation-Gate-Signature: t=1790769600,sha256=%s',
            hash_hmac('sha256', sprintf('1790769600.%s', $body), 'sesame'),
        ));
});

it('signs nothing for a webhook with no secret set', function () use ($github, $reported): void {
    $answer = new MockResponse('ok');
    $environment = Variables::of([...$github, 'MUTATION_GATE_WEBHOOK_URL' => 'https://hooks.example/w']);
    $sent = $reported(alertReporter(Channel::Webhook, $environment, [$answer]), Verdicts::failing()->withAccount(Previous::run('passed')));

    expect($sent)->toEqual(Written::to('the webhook'))
        ->and(headersOf($answer))->not->toContain('X-Mutation-Gate-Signature');
});

it('signs nothing for a chat, whatever secret is set', function () use ($github, $reported): void {
    $answer = new MockResponse('ok');
    $environment = Variables::of([...$github, 'MUTATION_GATE_DISCORD_URL' => 'https://hooks.example/d', 'MUTATION_GATE_WEBHOOK_SECRET' => 'sesame']);
    $reported(alertReporter(Channel::Discord, $environment, [$answer]), Verdicts::failing()->withAccount(Previous::run('passed')));

    expect(headersOf($answer))->not->toContain('X-Mutation-Gate-Signature');
});

it('says the first alert that was not sent', function () use ($github, $reported): void {
    $verdict = Verdicts::passing()->withAccount(Previous::run('failed', floor: 90.0));
    $environment = Variables::of([...$github, 'MUTATION_GATE_SLACK_URL' => 'https://hooks.example/slack']);
    $sent = $reported(alertReporter(Channel::Slack, $environment, [new MockResponse('gone', ['http_code' => 404]), new MockResponse('ok')]), $verdict);

    expect($sent)->toEqual(NotWritten::because('Slack answered 404: gone'));
});

it('sends nothing, and says why, where there is nothing to send or nowhere to send it', function (Variables $environment, Verdict $verdict, string $why) use ($reported): void {
    expect($reported(alertReporter(Channel::Slack, $environment, []), $verdict))->toEqual(NotWritten::because($why));
})->with([
    'no URL' => [
        Variables::of(['CI' => 'true', 'GITHUB_ACTIONS' => 'true']),
        Verdicts::failing()->withAccount(Previous::run('passed')),
        'MUTATION_GATE_SLACK_URL is not set, so no alert goes to Slack.',
    ],
    'outside CI' => [
        Variables::of(['MUTATION_GATE_SLACK_URL' => 'https://hooks.example/slack']),
        Verdicts::failing()->withAccount(Previous::run('passed')),
        'Alerts are sent from CI only.',
    ],
    'a CI the gate cannot name a run of' => [
        Variables::of(['CI' => 'true', 'MUTATION_GATE_SLACK_URL' => 'https://hooks.example/slack']),
        Verdicts::failing()->withAccount(Previous::run('passed')),
        'The gate names a run on GitHub, GitLab, Buildkite, CircleCI, Azure DevOps or Bitbucket, and not on this CI.',
    ],
    'off the default branch' => [
        Variables::of(['CI' => 'true', 'GITHUB_ACTIONS' => 'true', 'MUTATION_GATE_SLACK_URL' => 'https://hooks.example/slack']),
        Verdicts::failing(),
        'This run is not on the default branch, so it alerts nothing.',
    ],
    'cut short' => [
        Variables::of(['CI' => 'true', 'GITHUB_ACTIONS' => 'true', 'MUTATION_GATE_SLACK_URL' => 'https://hooks.example/slack']),
        Verdicts::failing()->withAccount(Previous::run('passed'))->cutShort(),
        'The run\'s budget cut it short, so it alerts nothing.',
    ],
    'no change' => [
        Variables::of(['CI' => 'true', 'GITHUB_ACTIONS' => 'true', 'MUTATION_GATE_SLACK_URL' => 'https://hooks.example/slack']),
        Verdicts::failing()->withAccount(Previous::run('failed')),
        'The default branch did not change state, so there is nothing to alert.',
    ],
]);

it('refuses a variable that is not named in text', function (string $options, Invalid $invalid): void {
    expect(alertReporter(Channel::Discord, Variables::of([]), [], $options))->toEqual($invalid);
})->with([
    'a URL variable as a number' => ['{"urlEnv": 3}', Invalid::because(Problem::at('urlEnv', 'expected text, got 3'))],
    'a secret variable as a list' => ['{"secretEnv": []}', Invalid::because(Problem::at('secretEnv', 'expected text, got a list'))],
]);

it('reads its own environment and posts over the network', function (): void {
    $reporter = Environment::during(
        ['MUTATION_GATE_SLACK_URL' => null, 'CI' => null],
        static fn(): AlertReporter|Invalid => AlertReporter::configured(Channel::Slack, Options::none(), new StoppedClock('2026-09-30T12:00:00Z')),
    );

    expect($reporter instanceof AlertReporter ? $reporter->report(Verdicts::failing()) : null)
        ->toEqual(NotWritten::because('MUTATION_GATE_SLACK_URL is not set, so no alert goes to Slack.'));
});
