<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Doctor\Online;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\Schedule;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\GitHubAnswering;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * What `--online` finds, in a project with these environment variables, asking this GitHub.
 *
 * @param array<string, string> $environment
 */
function foundOnline(string $project, array $environment, MockHttpClient $github): GitHubSettings|CannotTell|NotGiven
{
    return new Online($project, Variables::of($environment), $github)
        ->into(Observations::none()->withFiles(ProjectFiles::none()->withRunningTheGate(Paths::of(
            Path::of('.github/workflows/mutation.yml'),
            Path::of('.gitlab-ci.yml'),
        ))))
        ->asked()
        ->gitHub();
}

$online = foundOnline(...);

$github = static fn(string $repository): MockHttpClient => GitHubAnswering::client([
    sprintf('/repos/%s', $repository) => new JsonMockResponse(['default_branch' => 'main']),
    sprintf('/repos/%s/rules/branches/main', $repository) => new JsonMockResponse([]),
    sprintf('/repos/%s/actions/workflows?per_page=100', $repository) => new JsonMockResponse(['workflows' => [
        ['id' => 7, 'path' => '.github/workflows/mutation.yml', 'state' => 'active'],
        ['id' => 8, 'path' => '.gitlab-ci.yml', 'state' => 'active'],
    ]]),
]);

it('reads the repository GITHUB_REPOSITORY names, about the GitHub workflows that run the gate', function () use ($online, $github): void {
    $settings = $online(Scratch::directory(), ['GITHUB_REPOSITORY' => 'octo/gate'], $github('octo/gate'));
    $schedule = $settings instanceof GitHubSettings ? $settings->schedule() : null;

    expect($settings instanceof GitHubSettings ? [$settings->repository(), $settings->defaultBranch()] : [])->toBe(['octo/gate', 'main'])
        ->and($schedule instanceof Schedule ? $schedule->workflows() : null)->toEqual(Paths::of(Path::of('.github/workflows/mutation.yml')));
});

it('reads the repository git\'s origin remote is on, on the server GITHUB_SERVER_URL names', function () use ($online, $github): void {
    $repository = Repository::empty();
    $repository->git('remote', 'add', 'origin', 'https://git.example.com/octo/gate.git');
    $settings = $online($repository->root, ['GITHUB_SERVER_URL' => 'https://git.example.com'], $github('octo/gate'));

    expect($settings instanceof GitHubSettings ? $settings->repository() : '')->toBe('octo/gate')
        ->and($online($repository->root, [], $github('octo/gate')))
        ->toEqual(CannotTell::because('git\'s origin remote, https://git.example.com/octo/gate.git, is not a repository on github.com.'));
});

it('finds no repository without GITHUB_REPOSITORY or an origin remote, or with a name that is none', function () use ($online, $github): void {
    $settings = $online(Scratch::directory(), [], $github('octo/gate'));

    expect($settings instanceof CannotTell ? $settings->why() : '')->toStartWith('GITHUB_REPOSITORY is not set, and git names no origin remote: ')
        ->and($online(Scratch::directory(), ['GITHUB_REPOSITORY' => 'octo'], $github('octo/gate')))
        ->toEqual(CannotTell::because('octo is no repository name of the form owner/name.'));
});

it('asks with GITHUB_TOKEN, else GH_TOKEN, at GITHUB_API_URL', function (
    string $githubToken,
    string $ghToken,
    string $api,
    string $url,
    string $token,
) use ($online): void {
    $response = new JsonMockResponse(['default_branch' => 'main']);
    $environment = array_filter([
        'GITHUB_REPOSITORY' => 'octo/gate',
        'GITHUB_TOKEN' => $githubToken,
        'GH_TOKEN' => $ghToken,
        'GITHUB_API_URL' => $api,
    ], static fn(string $value): bool => $value !== '');

    $online(Scratch::directory(), $environment, new MockHttpClient($response));
    $headers = (string) json_encode($response->getRequestOptions()['headers']);

    expect($response->getRequestUrl())->toBe($url)
        ->and(str_contains($headers, $token))->toBeTrue()
        ->and(str_contains($headers, 'Authorization'))->toBe($token !== 'X-GitHub-Api-Version');
})->with([
    'GITHUB_TOKEN first' => ['one', 'two', '', 'https://api.github.com/repos/octo/gate', 'Authorization: Bearer one'],
    'GH_TOKEN else' => ['', 'two', 'https://git.example.com/api/v3', 'https://git.example.com/api/v3/repos/octo/gate', 'Authorization: Bearer two'],
    'no token' => ['', '', '', 'https://api.github.com/repos/octo/gate', 'X-GitHub-Api-Version'],
]);
