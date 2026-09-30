<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Api;
use NightWorksIO\MutationGate\Adapter\GitHub\RepositoryName;
use NightWorksIO\MutationGate\Adapter\GitHub\RepositorySettings;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\ForkApproval;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\Schedule;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Tests\Support\GitHubAnswering;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

const GATE_WORKFLOWS = ['.github/workflows/mutation.yml', '.github/workflows/nightly.yml'];

/**
 * What the settings reader reads of octo/gate, where GitHub answers so.
 *
 * @param array<string, MockResponse> $answers
 */
function settingsRead(array $answers): GitHubSettings|CannotTell
{
    $repository = RepositoryName::of('octo/gate');
    $settings = $repository instanceof RepositoryName
        ? RepositorySettings::of(Api::at(GitHubAnswering::client($answers), '', 'secret'), $repository)
        : null;

    return $settings instanceof RepositorySettings
        ? $settings->read(Paths::of(...array_map(Path::of(...), GATE_WORKFLOWS)))
        : CannotTell::because('No repository.');
}

$read = settingsRead(...);

$everything = [
    '/repos/octo/gate' => new JsonMockResponse(['default_branch' => 'trunk']),
    '/repos/octo/gate/rules/branches/trunk' => new JsonMockResponse([
        ['type' => 'deletion'],
        ['type' => 'required_status_checks', 'parameters' => ['required_status_checks' => [['context' => 'mutation / verdict']]]],
    ]),
    '/repos/octo/gate/branches/trunk' => new JsonMockResponse(['protection' => ['required_status_checks' => ['checks' => [['context' => 'build']]]]]),
    '/repos/octo/gate/actions/permissions/fork-pr-contributor-approval' => new JsonMockResponse(['approval_policy' => 'first_time_contributors']),
    '/repos/octo/gate/actions/workflows?per_page=100' => new JsonMockResponse(['workflows' => [
        ['id' => 7, 'path' => '.github/workflows/mutation.yml', 'state' => 'active'],
        ['id' => 8, 'path' => '.github/workflows/lint.yml', 'state' => 'disabled_inactivity'],
        ['id' => 9, 'path' => '.github/workflows/nightly.yml', 'state' => 'disabled_inactivity'],
    ]]),
    '/repos/octo/gate/actions/workflows/7/runs?event=schedule&per_page=1' => new JsonMockResponse(['workflow_runs' => [['created_at' => '2026-09-20T03:00:00Z']]]),
    '/repos/octo/gate/actions/workflows/9/runs?event=schedule&per_page=1' => new JsonMockResponse(['workflow_runs' => [['created_at' => '2026-09-28T03:00:00Z']]]),
];

it('reads the required checks, the fork approval policy and the gate\'s scheduled runs', function () use ($read, $everything): void {
    expect($read($everything))->toEqual(GitHubSettings::of(
        'octo/gate',
        'trunk',
        Listed::of('mutation / verdict', 'build'),
        ForkApproval::FirstTimeContributors,
        Schedule::of(
            Paths::of(...array_map(Path::of(...), GATE_WORKFLOWS)),
            Paths::of(Path::of('.github/workflows/nightly.yml')),
            Instant::at(new DateTimeImmutable('2026-09-28T03:00:00Z')),
        ),
    ));
});

it('takes the latest scheduled run whichever workflow made it', function () use ($read, $everything): void {
    $settings = $read([
        ...$everything,
        '/repos/octo/gate/actions/workflows/7/runs?event=schedule&per_page=1' => new JsonMockResponse(['workflow_runs' => [['created_at' => '2026-09-29T03:00:00Z']]]),
    ]);
    $schedule = $settings instanceof GitHubSettings ? $settings->schedule() : null;

    expect($schedule instanceof Schedule ? $schedule->lastRun() : null)
        ->toEqual(Instant::at(new DateTimeImmutable('2026-09-29T03:00:00Z')));
});

it('reads no scheduled run where none was made, or GitHub dated it in a way it does not read', function () use ($read, $everything): void {
    $settings = $read([
        ...$everything,
        '/repos/octo/gate/actions/workflows/7/runs?event=schedule&per_page=1' => new JsonMockResponse(['workflow_runs' => []]),
        '/repos/octo/gate/actions/workflows/9/runs?event=schedule&per_page=1' => new JsonMockResponse(['workflow_runs' => [['created_at' => 'yesterday']]]),
    ]);
    $schedule = $settings instanceof GitHubSettings ? $settings->schedule() : null;

    expect($schedule instanceof Schedule ? $schedule->lastRun() : null)->toEqual(NotGiven::value());
});

it('takes the checks branch protection requires where the rules require none', function () use ($read, $everything): void {
    $settings = $read([...$everything, '/repos/octo/gate/rules/branches/trunk' => new JsonMockResponse([])]);

    $required = $settings instanceof GitHubSettings ? $settings->required() : null;

    expect($required instanceof Listed ? [...$required] : [])->toBe(['build']);
});

it('says why of each setting GitHub would not show, and still reads the others', function () use ($read, $everything): void {
    $settings = $read(['/repos/octo/gate' => new JsonMockResponse(['default_branch' => 'trunk'])]);

    expect($settings instanceof GitHubSettings ? [$settings->required(), $settings->forkApproval(), $settings->schedule()] : [])
        ->each->toBeInstanceOf(CannotTell::class)
        ->and($read([...$everything, '/repos/octo/gate/branches/trunk' => new JsonMockResponse([])]))->toBeInstanceOf(GitHubSettings::class);
});

it('cannot tell a policy it does not know', function () use ($read, $everything): void {
    $settings = $read([
        ...$everything,
        '/repos/octo/gate/actions/permissions/fork-pr-contributor-approval' => new JsonMockResponse(['approval_policy' => 'everyone']),
    ]);

    expect($settings instanceof GitHubSettings ? $settings->forkApproval() : null)
        ->toEqual(CannotTell::because('GitHub answered the approval policy everyone, which the gate does not know.'));
});

it('cannot tell anything of a repository GitHub does not show', function () use ($read): void {
    expect($read([]))->toBeInstanceOf(CannotTell::class);
});
