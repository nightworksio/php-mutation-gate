<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\BadgeDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveredReport;
use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveryDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\JsonReportFile;
use NightWorksIO\MutationGate\Adapter\GitHub\Annotations;
use NightWorksIO\MutationGate\Adapter\GitHub\PlannedMarkdown;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Adapter\GitHub\RecheckedMarkdown;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Adapter\Otlp\OtlpReporter;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Reporting;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Config\Badge;
use NightWorksIO\MutationGate\Config\Option;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\Plan\PlanEstimates;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\ConfigurableReporter;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

afterEach(function (): void {
    Scratch::sweep();
});

/** A run on the default branch, `main`. */
function reportingOnMain(): RunOn
{
    return RunOn::at(Scope::branch('main'), Scope::branch('main'));
}

/**
 * The reporters these settings and variables choose for a run on this ref.
 *
 * @return list<Reporter>|Invalid|CannotJudge
 */
function reportersOf(Settings $settings, Variables $environment, RunOn $runOn): array|Invalid|CannotJudge
{
    $chosen = new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER))));

    return new Reporting($chosen, $environment)->reporters($settings, $runOn);
}

/**
 * The class of each reporter, in order, or of why there are none.
 *
 * @param  list<Reporter>|Invalid|CannotJudge $reporters
 * @return list<class-string>
 */
function reporterClasses(array|Invalid|CannotJudge $reporters): array
{
    return is_array($reporters)
        ? array_map(static fn(Reporter $reporter): string => $reporter::class, $reporters)
        : [$reporters::class];
}

it('runs no reporter but the console where nothing asks for one', function (): void {
    expect(reportersOf(Flows::settings(), Variables::of([]), reportingOnMain()))->toBe([]);
});

it('runs a reporter for each entry of reports, writing where its path says', function (): void {
    $project = Scratch::directory();
    $here = (string) getcwd();
    chdir($project);
    $chosen = reportersOf(Flows::settings(Report::json('build/report.json')), Variables::of([]), reportingOnMain());
    $reporter = is_array($chosen) ? $chosen[0] : $chosen;
    $written = $reporter instanceof JsonReportFile ? $reporter->report(Verdicts::passing()) : $reporter;
    chdir($here);

    expect($reporter)->toBeInstanceOf(JsonReportFile::class)
        ->and($written)->toEqual(Written::to('build/report.json'))
        ->and(file_exists(sprintf('%s/build/report.json', $project)))->toBeTrue();
});

it('hands a reporter its path beside the options its entry gives', function (): void {
    $project = Scratch::directory();
    $here = (string) getcwd();
    chdir($project);
    $entry = Report::writing('badge', 'publish', Option::nested('colors', Option::of('blue', 50), Option::of('red', 0)));
    $chosen = reportersOf(Flows::settings($entry), Variables::of([]), reportingOnMain());
    $reporter = is_array($chosen) ? $chosen[0] : $chosen;
    $badge = $reporter instanceof BadgeDirectory ? $reporter->report(Verdicts::passing()) : $reporter;
    chdir($here);

    expect($badge)->toEqual(Written::to('publish'))
        ->and((string) file_get_contents(sprintf('%s/publish/badge.json', $project)))->toContain('"color": "blue"');
});

it('says which entry of reports cannot be built', function (): void {
    $settings = Flows::settings(Report::uses('otlp'), Report::uses(sprintf('\\%s', ConfigurableReporter::class)));

    expect(reportersOf($settings, Variables::of([]), reportingOnMain()))->toEqual(Invalid::because(
        Problem::at('reports[1].with.channel', 'expected a channel name, got nothing'),
        Problem::at('reports[1].with', 'needs a channel'),
    ));
});

it('annotates and summarises under GitHub Actions, and comments on a pull request it can write to', function (
    Variables $environment,
    array $expected,
): void {
    $pullRequest = RunOn::at(Scope::pullRequest(7), Scope::branch('main'));

    expect(reporterClasses(reportersOf(Flows::settings(), $environment, $pullRequest)))->toBe($expected);
})->with([
    'a pull request with a token' => [
        Variables::of([
            'GITHUB_ACTIONS' => 'true',
            'GITHUB_EVENT_NAME' => 'pull_request_target',
            'GITHUB_TOKEN' => 'secret',
        ]),
        [Annotations::class, StepSummary::class, PullRequestComment::class],
    ],
    'a pull request with no token' => [
        Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request']),
        [Annotations::class, StepSummary::class],
    ],
    'a push' => [
        Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'push', 'GITHUB_TOKEN' => 'secret']),
        [Annotations::class, StepSummary::class],
    ],
    'no GitHub Actions' => [Variables::of(['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => 'secret']), []],
    'GitHub Actions set to something else' => [Variables::of(['GITHUB_ACTIONS' => 'false']), []],
]);

it('draws the badge in CI on the default branch, in the colours the config sets', function (): void {
    $settings = Flows::settings(Badge::colour('green', 90), Badge::colour('red', 0));
    $chosen = reportersOf($settings, Variables::of(['CI' => 'true']), reportingOnMain());

    expect($chosen)->toEqual([BadgeDirectory::configured(
        Configs::options('{"colors": {"green": 90, "red": 0}}'),
        new SystemClock(),
    )]);
});

it('draws no badge outside CI, off the default branch, or where the run has no ref or knows no default', function (
    Variables $environment,
    RunOn $runOn,
): void {
    expect(reportersOf(Flows::settings(), $environment, $runOn))->toBe([]);
})->with([
    'outside CI' => [Variables::of([]), RunOn::at(Scope::branch('main'), Scope::branch('main'))],
    'a pull request' => [Variables::of(['CI' => 'true']), RunOn::at(Scope::pullRequest(7), Scope::branch('main'))],
    'a detached HEAD' => [Variables::of(['CI' => 'true']), RunOn::detached(Scope::branch('main'))],
    'no default branch' => [
        Variables::of(['CI' => 'true']),
        RunOn::at(Scope::branch('main'), CannotTell::because('unnamed')),
    ],
]);

/** A registry whose sticky comment answers through these responses, as `gate-bot`, on pull request 12, with a token or not. */
function reportingCommentingThrough(MockHttpClient $client, bool $withToken = true): Chosen
{
    $environment = [
        'GITHUB_EVENT_NAME' => 'pull_request',
        'GITHUB_TOKEN' => $withToken ? 'secret' : '',
        'GITHUB_REPOSITORY' => 'octo/gate',
        'GITHUB_API_URL' => 'https://api.github.example',
        'GITHUB_SERVER_URL' => 'https://github.example',
        'GITHUB_RUN_ID' => '7',
    ];
    $event = (string) json_encode(['pull_request' => [
        'number' => 12,
        'head' => ['repo' => ['full_name' => 'octo/gate']],
        'base' => ['repo' => ['full_name' => 'octo/gate']],
    ]]);

    return new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))->withReporter(
        BuiltinReporter::GitHubComment->named(),
        static fn(): Reporter => PullRequestComment::inRun($environment, $event, $client, 'gate-bot'),
    ));
}

it('writes the sticky comment in its planned state on a pull request it can write to, saying where', function (): void {
    $post = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-9']);
    $client = new MockHttpClient([new JsonMockResponse([]), $post]);
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(12), Scope::branch('main')));
    $settings = Flows::settings();
    $onAPullRequest = Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => 'secret']);

    $said = new Reporting(reportingCommentingThrough($client), $onAPullRequest)->planned($settings, $plan);

    expect($said)->toBe([Written::to('https://github.example/octo/gate/pull/12#issuecomment-9')->said()])
        ->and($post->getRequestMethod())->toBe('POST')
        ->and(Decoded::at(is_string($post->getRequestOptions()['body']) ? $post->getRequestOptions()['body'] : '', 'body'))
        ->toBe(PlannedMarkdown::comment(
            PlanEstimates::of($plan, $settings->shards()->setup())->work(),
            'https://github.example/octo/gate/actions/runs/7',
        ));
});

it('writes no planned state where the run chooses no comment', function (Variables $environment): void {
    $client = new MockHttpClient([]);
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(12), Scope::branch('main')));

    expect(new Reporting(reportingCommentingThrough($client), $environment)->planned(Flows::settings(), $plan))->toBe([])
        ->and($client->getRequestsCount())->toBe(0);
})->with([
    'a push' => [Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'push', 'GITHUB_TOKEN' => 'secret'])],
    'a pull request with no token' => [Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request'])],
    'no GitHub Actions' => [Variables::of([])],
]);

it('says which entry of reports cannot be built before it writes any planned state', function (): void {
    $settings = Flows::settings(Report::uses(sprintf('\\%s', ConfigurableReporter::class)));
    $plan = Planned::twoShards()->on(reportingOnMain());

    expect(new Reporting(reportingCommentingThrough(new MockHttpClient([])), Variables::of([]))->planned($settings, $plan))
        ->toEqual(Invalid::because(
            Problem::at('reports[0].with.channel', 'expected a channel name, got nothing'),
            Problem::at('reports[0].with', 'needs a channel'),
        ));
});

it('leaves the planned state in the delivery under --deliver-later, on a pull request, with no token', function (): void {
    $project = Scratch::directory();
    $delivery = DeliveryDirectory::of(Directory::at($project), Stage::Planned);
    $client = new MockHttpClient([]);
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(12), Scope::branch('main')));
    $settings = Flows::settings();
    $onAPullRequest = Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request']);

    $said = new Reporting(reportingCommentingThrough($client, withToken: false), $onAPullRequest)
        ->deliveringLater($delivery)
        ->planned($settings, $plan);
    $left = DeliveryFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/delivery/planned/delivery.json', $project)));

    expect($said)->toBe([sprintf('Wrote %s/.mutation-gate/delivery/planned/delivery.json.', $project)])
        ->and($client->getRequestsCount())->toBe(0)
        ->and($left)->toEqual(Delivery::none()->withComment(PlannedMarkdown::comment(
            PlanEstimates::of($plan, $settings->shards()->setup())->work(),
            'https://github.example/octo/gate/actions/runs/7',
        )));
});

it('chooses the comment on a pull request with no token under --deliver-later, and leaves each credentialed reporter\'s payload in the delivery', function (): void {
    $delivery = DeliveryDirectory::of(Directory::at(Scratch::directory()), Stage::Verdict);
    $onAPullRequest = Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request']);
    $chosen = new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER))));
    $settings = Flows::settings(Report::uses('otlp'), Report::json('build/mutation.json'));
    $reporters = new Reporting($chosen, $onAPullRequest)->deliveringLater($delivery)->reporters($settings, reportingOnMain());

    expect(reporterClasses($reporters))->toBe([
        DeliveredReport::class,
        JsonReportFile::class,
        Annotations::class,
        StepSummary::class,
        DeliveredReport::class,
    ])
        ->and(reporterClasses(new Reporting($chosen, $onAPullRequest)->reporters($settings, reportingOnMain())))
        ->toBe([OtlpReporter::class, JsonReportFile::class, Annotations::class, StepSummary::class]);
});

it('writes the re-checked survivors over the planned comment, and into the step summary, saying where', function (): void {
    $patch = new JsonMockResponse(['html_url' => 'https://github.example/octo/gate/pull/12#issuecomment-5']);
    $planned = PlannedMarkdown::comment(PlanEstimates::of(Planned::twoShards(), Flows::settings()->shards()->setup())->work(), '');
    $client = new MockHttpClient([
        new JsonMockResponse([['id' => 5, 'user' => ['login' => 'gate-bot'], 'body' => $planned]]),
        $patch,
    ]);
    $summary = sprintf('%s/summary.md', Scratch::directory());
    $chosen = new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))
        ->withReporter(
            BuiltinReporter::GitHubComment->named(),
            static fn(): Reporter => PullRequestComment::inRun(
                ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => 'secret', 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_API_URL' => 'https://api.github.example'],
                (string) json_encode(['pull_request' => ['number' => 12]]),
                $client,
                'gate-bot',
            ),
        )
        ->withReporter(
            BuiltinReporter::GitHubSummary->named(),
            static fn(): Reporter => StepSummary::appendingTo($summary, '', new SystemClock()),
        ));
    $onAPullRequest = Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => 'secret']);
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(12), Scope::branch('main')));

    $said = new Reporting($chosen, $onAPullRequest)->rechecked(Flows::settings(), $plan, Rechecks::mixed());

    expect($said)->toBe([
        Written::to($summary)->said(),
        Written::to('https://github.example/octo/gate/pull/12#issuecomment-5')->said(),
    ])
        ->and(file_get_contents($summary))->toBe(RecheckedMarkdown::comment(Rechecks::mixed(), ''))
        ->and(Decoded::at(is_string($patch->getRequestOptions()['body']) ? $patch->getRequestOptions()['body'] : '', 'body'))
        ->toBe(RecheckedMarkdown::comment(Rechecks::mixed(), 'https://github.com/octo/gate/actions/runs/'));
});

it('leaves the re-checked survivors in the delivery under --deliver-later, to be written only over the planned comment, with no token', function (): void {
    $project = Scratch::directory();
    $client = new MockHttpClient([]);
    $summary = sprintf('%s/summary.md', $project);
    $environment = [
        'GITHUB_EVENT_NAME' => 'pull_request',
        'GITHUB_REPOSITORY' => 'octo/gate',
        'GITHUB_SERVER_URL' => 'https://github.example',
        'GITHUB_RUN_ID' => '7',
    ];
    $chosen = new Chosen(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))
        ->withReporter(
            BuiltinReporter::GitHubComment->named(),
            static fn(): Reporter => PullRequestComment::inRun($environment, '{"pull_request": {"number": 12}}', $client, ''),
        )
        ->withReporter(
            BuiltinReporter::GitHubSummary->named(),
            static fn(): Reporter => StepSummary::appendingTo($summary, '', new SystemClock()),
        ));
    $onAPullRequest = Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_EVENT_NAME' => 'pull_request']);
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(12), Scope::branch('main')));

    $said = new Reporting($chosen, $onAPullRequest)
        ->deliveringLater(DeliveryDirectory::of(Directory::at($project), Stage::Survivors))
        ->rechecked(Flows::settings(), $plan, Rechecks::mixed());
    $left = DeliveryFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/delivery/survivors/delivery.json', $project)));

    expect($said)->toBe([
        Written::to($summary)->said(),
        sprintf('Wrote %s/.mutation-gate/delivery/survivors/delivery.json.', $project),
    ])
        ->and($client->getRequestsCount())->toBe(0)
        ->and($left)->toEqual(Delivery::none()->withCommentOverPlanned(RecheckedMarkdown::comment(
            Rechecks::mixed(),
            'https://github.example/octo/gate/actions/runs/7',
        )));
});

it('writes no re-checked survivors where the run chooses neither, and says why where the reports cannot be built', function (): void {
    $client = new MockHttpClient([]);
    $plan = Planned::twoShards()->on(RunOn::at(Scope::pullRequest(12), Scope::branch('main')));
    $broken = Flows::settings(Report::uses(sprintf('\\%s', ConfigurableReporter::class)));

    expect(new Reporting(reportingCommentingThrough($client), Variables::of([]))->rechecked(Flows::settings(), $plan, Rechecks::mixed()))
        ->toBe([])
        ->and($client->getRequestsCount())->toBe(0)
        ->and(new Reporting(reportingCommentingThrough($client), Variables::of([]))->rechecked($broken, $plan, Rechecks::mixed()))
        ->toBe(['reports[0].with.channel: expected a channel name, got nothing', 'reports[0].with: needs a channel'])
        ->and(new Reporting(reportingCommentingThrough($client), Variables::of([]))->rechecked(
            Flows::settings(Report::uses('\\Acme\\Missing\\Reporter')),
            $plan,
            Rechecks::mixed(),
        ))
        ->toBe([sprintf(
            '\\Acme\\Missing\\Reporter is not a class that implements %s and %s, so a config cannot choose it.',
            Reporter::class,
            Configurable::class,
        )]);
});
