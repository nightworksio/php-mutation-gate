<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\AlertReporter;
use NightWorksIO\MutationGate\Adapter\Alert\Channel;
use NightWorksIO\MutationGate\Adapter\Alert\Delivery;
use NightWorksIO\MutationGate\Adapter\Alert\Pause;
use NightWorksIO\MutationGate\Adapter\Console\ConsoleReport;
use NightWorksIO\MutationGate\Adapter\Console\ProblemsReport;
use NightWorksIO\MutationGate\Adapter\Filesystem\BadgeDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\CodeQualityReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\HtmlReportDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\JsonReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\JUnitReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\KillMatrixFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\SarifReportFile;
use NightWorksIO\MutationGate\Adapter\Filesystem\TestsReportFile;
use NightWorksIO\MutationGate\Adapter\GitHub\Annotations;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Adapter\Otlp\OtlpReporter;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

// What every reporter answers for a failing, a passing and an empty verdict,
// and for one a budget cut short: that it wrote, or why it did not, and never
// an exception. One line per implementation.

afterEach(function (): void {
    Scratch::sweep();
});

$pullRequest = static fn(): string => (string) json_encode(['pull_request' => [
    'number' => 12,
    'head' => ['repo' => ['full_name' => 'octo/gate']],
    'base' => ['repo' => ['full_name' => 'octo/gate']],
]]);

$alerting = static fn(Channel $channel): Reporter => AlertReporter::to(
    $channel,
    Variables::of([
        'CI' => 'true',
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_REPOSITORY' => 'octo/gate',
        'GITHUB_REF' => 'refs/heads/main',
        'GITHUB_SHA' => '5eeca8f',
        'GITHUB_RUN_ID' => '7',
        $channel->urlEnv() => 'https://hooks.example/alert',
    ]),
    Delivery::over(new MockHttpClient([new MockResponse('ok')]), new StoppedClock('2026-09-30T12:00:00Z'), Pause::for(...)),
    new StoppedClock('2026-09-30T12:00:00Z'),
    $channel->urlEnv(),
    AlertReporter::SECRET_ENV,
);

$reporters = [
    'the fake' => fn(): Reporter => new ReporterFake(),
    'the console' => fn(): Reporter => ConsoleReport::to(new BufferedOutput()),
    'the problems output' => fn(): Reporter => ProblemsReport::to(new BufferedOutput(), Root::of(Scratch::directory()), ProblemsShown::All),
    'JSON' => fn(): Reporter => JsonReportFile::at(sprintf('%s/mutation.json', Scratch::directory())),
    'JUnit' => fn(): Reporter => JUnitReportFile::at(sprintf('%s/junit.xml', Scratch::directory())),
    'SARIF' => fn(): Reporter => SarifReportFile::at(sprintf('%s/mutation.sarif', Scratch::directory())),
    'SARIF for an editor' => fn(): Reporter => SarifReportFile::rootedAt(sprintf('%s/mutation.sarif', Scratch::directory()), '/work/gate'),
    'GitLab Code Quality' => fn(): Reporter => CodeQualityReportFile::at(sprintf('%s/gl-code-quality.json', Scratch::directory())),
    'the kill matrix' => fn(): Reporter => KillMatrixFile::at(sprintf('%s/kill-matrix.csv', Scratch::directory())),
    'the tests report' => fn(): Reporter => TestsReportFile::at(sprintf('%s/tests.json', Scratch::directory())),
    'HTML' => fn(): Reporter => HtmlReportDirectory::at(
        sprintf('%s/html', Scratch::directory()),
        Scratch::directory(),
        Schema::at('resources/mutation-testing-elements'),
    ),
    'GitHub annotations' => fn(): Reporter => Annotations::printingTo(sprintf('%s/annotations', Scratch::directory())),
    'the step summary' => fn(): Reporter => StepSummary::appendingTo(sprintf('%s/summary.md', Scratch::directory()), '', new StoppedClock('2026-09-30T12:00:00Z')),
    'the pull request comment' => fn(): Reporter => PullRequestComment::inRun(
        ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_TOKEN' => 'secret', 'GITHUB_REPOSITORY' => 'octo/gate'],
        $pullRequest(),
        new MockHttpClient([new JsonMockResponse([]), new JsonMockResponse(['html_url' => 'https://github.com/octo/gate/pull/12'])]),
        'gate-bot',
    ),
    'Slack' => fn(): Reporter => $alerting(Channel::Slack),
    'Discord' => fn(): Reporter => $alerting(Channel::Discord),
    'the webhook' => fn(): Reporter => $alerting(Channel::Webhook),
    'OTLP' => fn(): Reporter => OtlpReporter::to(
        Variables::of([]),
        new MockHttpClient(static fn(): MockResponse => new MockResponse('{}')),
        new StoppedClock('2026-09-30T12:00:00Z'),
        'https://otel.example',
    ),
    'the badge' => fn(): Reporter => BadgeDirectory::at(
        sprintf('%s/publish', Scratch::directory()),
        BadgeColors::defaults(),
        'abc123',
        new StoppedClock('2026-09-30T12:00:00Z'),
    ),
];

it('reports a verdict of each kind, saying where it wrote or why it could not', function (Reporter $reporter, string $verdict): void {
    $answer = $reporter->report(Verdicts::named($verdict));

    expect($answer instanceof Written ? $answer->where() : $answer->why())->not->toBe('');
})->with($reporters)->with([
    'failed' => ['failing'],
    'passed' => ['passing'],
    'nothing to mutate' => ['empty'],
    'cut short' => ['cut short'],
]);

it('writes a failing verdict, and leaves a file it wrote where it says', function (Reporter $reporter): void {
    $answer = $reporter->report(Verdicts::failing()->withAccount(Previous::run('passed')));
    $where = $answer instanceof Written ? $answer->where() : '';

    expect($answer)->toBeInstanceOf(Written::class)
        ->and(str_starts_with($where, '/') ? file_exists($where) : $where !== '')->toBeTrue();
})->with($reporters);
