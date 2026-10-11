<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Doctor\Online;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Doctor\AnonymousReadsRefused;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\GitHubAnswering;
use NightWorksIO\MutationGate\Tests\Support\OnlineAnswers;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

$holds = [
    'holds:src/Adapter/Azure/PublicAccess.php',
    'holds:src/Adapter/Git/Git.php',
    'holds:src/Adapter/GitHub/RepositoryName.php',
    'holds:src/Adapter/GitHub/RepositorySettings.php',
    'holds:src/Cli/Doctor/Online.php',
];

afterEach(function (): void {
    Scratch::sweep();
});


$makeOnline = static fn(): Closure => OnlineAnswers::found(...);

$github = static fn(string $repository): MockHttpClient => GitHubAnswering::client([
    sprintf('/repos/%s', $repository) => new JsonMockResponse(['default_branch' => 'main']),
    sprintf('/repos/%s/rules/branches/main', $repository) => new JsonMockResponse([]),
    sprintf('/repos/%s/actions/workflows?per_page=100', $repository) => new JsonMockResponse(['workflows' => [
        ['id' => 7, 'path' => '.github/workflows/mutation.yml', 'state' => 'active'],
        ['id' => 8, 'path' => '.gitlab-ci.yml', 'state' => 'active'],
    ]]),
]);

it('reads the repository git\'s origin remote is on, on the server GITHUB_SERVER_URL names', function () use ($makeOnline, $github): void {
    $online = $makeOnline();

    $repository = Repository::empty();
    $repository->git('remote', 'add', 'origin', 'https://git.example.com/octo/gate.git');
    $settings = $online($repository->root, ['GITHUB_SERVER_URL' => 'https://git.example.com'], $github('octo/gate'));

    expect($settings instanceof GitHubSettings ? $settings->repository() : '')->toBe('octo/gate')
        ->and($online($repository->root, [], $github('octo/gate')))
        ->toEqual(CannotTell::because('git\'s origin remote, https://git.example.com/octo/gate.git, is not a repository on github.com.'));
})->group(...$holds);

it('finds no repository without GITHUB_REPOSITORY or an origin remote, or with a name that is none', function () use ($makeOnline, $github): void {
    $online = $makeOnline();

    $settings = $online(Scratch::directory(), [], $github('octo/gate'));

    expect($settings instanceof CannotTell ? $settings->why() : '')->toStartWith('GITHUB_REPOSITORY is not set, and git names no origin remote: ')
        ->and($online(Scratch::directory(), ['GITHUB_REPOSITORY' => 'octo'], $github('octo/gate')))
        ->toEqual(CannotTell::because('octo is no repository name of the form owner/name.'));
})->group(...$holds);

it('finds the Azure account that refuses anonymous reads of the store\'s public container', function (): void {
    $azure = ['use' => 'azure', 'with' => [
        'account' => 'acme',
        'container' => 'ledgers',
        'publicContainer' => 'public',
        'publicUrl' => 'https://acme.blob.core.windows.net/public',
    ]];
    $asked = static fn(array $store, Cloud $cloud): AnonymousReadsRefused|NotGiven => new Online(Scratch::directory(), Variables::of([]), $cloud->client())
        ->into(Observations::none()->withSettings(Configs::settings(['runner' => 'pest', 'proofs' => ['store' => $store]])))
        ->asked()
        ->anonymousReads();
    $refusing = new Cloud()->answering('https://acme.blob.core.windows.net/public?restype=container', 409, 'PublicAccessNotPermitted');

    expect($asked($azure, $refusing))->toEqual(AnonymousReadsRefused::by('acme', 'https://acme.blob.core.windows.net/public'))
        ->and($asked($azure, new Cloud()->answering('https://acme.blob.core.windows.net/public?restype=container', 404, 'ResourceNotFound')))
        ->toEqual(NotGiven::value())
        ->and($asked(['use' => 's3', 'with' => ['bucket' => 'b', 'publicUrl' => 'https://acme.blob.core.windows.net/public']], $refusing))
        ->toEqual(NotGiven::value())
        ->and($asked(['use' => 'acme-store', 'with' => [...$azure['with'], 'prefix' => 'mutation-gate']], $refusing))
        ->toEqual(NotGiven::value())
        ->and(new Online(Scratch::directory(), Variables::of([]), $refusing->client())->into(Observations::none())->asked()->anonymousReads())
        ->toEqual(NotGiven::value());
})->group(...$holds);
