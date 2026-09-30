<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\BadgeDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\JsonReportFile;
use NightWorksIO\MutationGate\Adapter\GitHub\Annotations;
use NightWorksIO\MutationGate\Adapter\GitHub\PullRequestComment;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
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
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

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
    $chosen = new Chosen(new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE))));

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
    $file = sprintf('%s/build/report.json', Scratch::directory());
    $settings = Flows::settings(Report::json($file));
    $chosen = reportersOf($settings, Variables::of([]), reportingOnMain());
    $reporter = is_array($chosen) ? $chosen[0] : $chosen;

    expect($reporter)->toBeInstanceOf(JsonReportFile::class)
        ->and($reporter instanceof JsonReportFile ? $reporter->report(Verdicts::passing()) : $reporter)
        ->toEqual(Written::to($file))
        ->and(file_exists($file))->toBeTrue();
});

it('hands a reporter its path beside the options its entry gives', function (): void {
    $directory = sprintf('%s/publish', Scratch::directory());
    $entry = Report::uses('badge', $directory, Option::nested('colors', Option::of('blue', 50), Option::of('red', 0)));
    $chosen = reportersOf(Flows::settings($entry), Variables::of([]), reportingOnMain());
    $reporter = is_array($chosen) ? $chosen[0] : $chosen;
    $badge = $reporter instanceof BadgeDirectory ? $reporter->report(Verdicts::passing()) : $reporter;

    expect($badge)->toEqual(Written::to($directory))
        ->and((string) file_get_contents(sprintf('%s/badge.json', $directory)))->toContain('"color": "blue"');
});

it('says which entry of reports cannot be built', function (): void {
    $settings = Flows::settings(
        Report::uses('console'),
        Report::uses('badge', 'publish', Option::nested('colors', Option::of('green', 'high'))),
    );

    expect(reportersOf($settings, Variables::of([]), reportingOnMain()))->toEqual(Invalid::because(Problem::at(
        'reports[1].with.colors',
        'Each badge colour maps to the lowest score that earns it.',
    )));
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
        Options::ofJson('{"colors": {"green": 90, "red": 0}}'),
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
