<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Gcs\ExternalAccount;
use NightWorksIO\MutationGate\Adapter\Gcs\GitHubIdentity;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\ExternalAccounts;

/** A GitHub Actions job granted `id-token: write`, with the provider and service account its federation names. */
const GITHUB_IDENTITY = [
    'ACTIONS_ID_TOKEN_REQUEST_URL' => 'https://pipelines.actions.githubusercontent.com/oidc?api-version=2.0',
    'ACTIONS_ID_TOKEN_REQUEST_TOKEN' => 'request-token',
    'MUTATION_GATE_GCS_PROVIDER' => 'projects/123/locations/global/workloadIdentityPools/github/providers/gate',
    'MUTATION_GATE_GCS_SERVICE_ACCOUNT' => 'gate@acme.iam.gserviceaccount.com',
];

/** GitHub's token for the provider's default audience, as `google-github-actions/auth` asks for it. */
const GITHUB_IDENTITY_REQUEST = 'https://pipelines.actions.githubusercontent.com/oidc?api-version=2.0&audience='
    . 'https%3A%2F%2Fiam.googleapis.com%2Fprojects%2F123%2Flocations%2Fglobal%2FworkloadIdentityPools%2Fgithub%2Fproviders%2Fgate';

it('exchanges GitHub\'s own token at Google\'s security token service, and impersonates the service account with it', function (): void {
    $cloud = new Cloud()
        ->answering(GITHUB_IDENTITY_REQUEST, 200, '{"count": 1, "value": "github-jwt"}')
        ->answering(ExternalAccounts::STS, 200, '{"access_token": "federated"}')
        ->answering(ExternalAccounts::IMPERSONATE, 200, '{"accessToken": "service-account", "expireTime": "2026-10-05T12:00:00Z"}');
    $account = GitHubIdentity::in(Variables::of(GITHUB_IDENTITY));
    $token = $account instanceof ExternalAccount ? $account->token($cloud->exchange()) : $account;
    parse_str($cloud->requests[1]['body'], $exchanged);

    expect($token)->toEqual(Token::bearer('service-account'))
        ->and([$cloud->requests[0]['method'], $cloud->requests[0]['url'], $cloud->requests[0]['headers']['authorization']])
        ->toBe(['GET', GITHUB_IDENTITY_REQUEST, 'Bearer request-token'])
        ->and([$cloud->requests[1]['url'], $exchanged['audience'] ?? '', $exchanged['subject_token'] ?? '', $exchanged['subject_token_type'] ?? ''])
        ->toBe([ExternalAccounts::STS, ExternalAccounts::AUDIENCE, 'github-jwt', 'urn:ietf:params:oauth:token-type:jwt'])
        ->and([$cloud->requests[2]['url'], $cloud->requests[2]['headers']['authorization']])->toBe([ExternalAccounts::IMPERSONATE, 'Bearer federated'])
        ->and($cloud->requests)->toHaveCount(3);
});

it('names no identity where neither the provider nor the service account is set', function (): void {
    expect(GitHubIdentity::in(Variables::of([...GITHUB_IDENTITY, 'MUTATION_GATE_GCS_PROVIDER' => '', 'MUTATION_GATE_GCS_SERVICE_ACCOUNT' => ''])))
        ->toEqual(NotGiven::value());
});

it('refuses a provider, a service account or a job that is not what federation with GitHub needs', function (string $variable, string $value, string $why): void {
    expect(GitHubIdentity::in(Variables::of([...GITHUB_IDENTITY, $variable => $value])))->toEqual(Invalid::because(Problem::at('', $why)));
})->with([
    'a provider that is none' => ['MUTATION_GATE_GCS_PROVIDER', 'projects/123/locations/global/workloadIdentityPools/github', 'MUTATION_GATE_GCS_PROVIDER is not a provider, projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>.'],
    'a provider in a project named, not numbered' => ['MUTATION_GATE_GCS_PROVIDER', 'projects/acme/locations/global/workloadIdentityPools/github/providers/gate', 'MUTATION_GATE_GCS_PROVIDER is not a provider, projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>.'],
    'a provider whose id leads elsewhere' => ['MUTATION_GATE_GCS_PROVIDER', 'projects/123/locations/global/workloadIdentityPools/github/providers/gate/../../x', 'MUTATION_GATE_GCS_PROVIDER is not a provider, projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>.'],
    'a provider that climbs' => ['MUTATION_GATE_GCS_PROVIDER', 'projects/123/locations/global/workloadIdentityPools/../providers/gate', 'MUTATION_GATE_GCS_PROVIDER is not a provider, projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>.'],
    'a person, not a service account' => ['MUTATION_GATE_GCS_SERVICE_ACCOUNT', 'gate@acme.com', 'MUTATION_GATE_GCS_SERVICE_ACCOUNT is not a service account, <name>@<project>.iam.gserviceaccount.com.'],
    'a service account that leads elsewhere' => ['MUTATION_GATE_GCS_SERVICE_ACCOUNT', 'gate@acme.iam.gserviceaccount.com/x:y', 'MUTATION_GATE_GCS_SERVICE_ACCOUNT is not a service account, <name>@<project>.iam.gserviceaccount.com.'],
    'no service account' => ['MUTATION_GATE_GCS_SERVICE_ACCOUNT', '', 'MUTATION_GATE_GCS_SERVICE_ACCOUNT is not a service account, <name>@<project>.iam.gserviceaccount.com.'],
    'no provider' => ['MUTATION_GATE_GCS_PROVIDER', '', 'MUTATION_GATE_GCS_PROVIDER is not a provider, projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>.'],
    'no OIDC request' => ['ACTIONS_ID_TOKEN_REQUEST_URL', '', 'GitHub hands this job no OIDC token for the gcs store: grant the job id-token: write.'],
    'no OIDC request token' => ['ACTIONS_ID_TOKEN_REQUEST_TOKEN', '', 'GitHub hands this job no OIDC token for the gcs store: grant the job id-token: write.'],
    'an OIDC request over plain HTTP' => ['ACTIONS_ID_TOKEN_REQUEST_URL', 'http://pipelines.actions.githubusercontent.com/oidc', 'GitHub hands this job no OIDC token for the gcs store: grant the job id-token: write.'],
]);

it('asks for the audience as the first parameter where GitHub\'s request URL holds none', function (): void {
    $cloud = new Cloud()->answering('https://token.example/oidc?audience=https%3A%2F%2Fiam.googleapis.com%2Fprojects%2F123%2Flocations%2Fglobal%2FworkloadIdentityPools%2Fgithub%2Fproviders%2Fgate', 401, 'no');
    $account = GitHubIdentity::in(Variables::of([...GITHUB_IDENTITY, 'ACTIONS_ID_TOKEN_REQUEST_URL' => 'https://token.example/oidc']));
    $token = $account instanceof ExternalAccount ? $account->token($cloud->exchange()) : $account;

    expect($token)->toBeInstanceOf(CannotJudge::class)
        ->and($cloud->requests[0]['url'] ?? '')->toBe('https://token.example/oidc?audience=https%3A%2F%2Fiam.googleapis.com%2Fprojects%2F123%2Flocations%2Fglobal%2FworkloadIdentityPools%2Fgithub%2Fproviders%2Fgate');
});
