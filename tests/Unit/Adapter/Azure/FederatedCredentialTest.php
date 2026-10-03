<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\FederatedCredential;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Tests\Support\Cloud;

const AZURE_REQUEST_URL = 'https://pipelines.actions.githubusercontent.com/oidc?api-version=2.0';
const AZURE_ASKED = 'https://pipelines.actions.githubusercontent.com/oidc?api-version=2.0&audience=api%3A%2F%2FAzureADTokenExchange';
const AZURE_ENTRA = 'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token';

/** @return array<string, string> */
function azureGitHub(string $url = AZURE_REQUEST_URL): array
{
    return [
        'ACTIONS_ID_TOKEN_REQUEST_URL' => $url,
        'ACTIONS_ID_TOKEN_REQUEST_TOKEN' => 'request-token',
        'AZURE_TENANT_ID' => 'contoso.onmicrosoft.com',
        'AZURE_CLIENT_ID' => '00000000-0000-0000-0000-000000000001',
    ];
}

it('exchanges GitHub\'s token for the federated credential\'s audience at Entra ID, once', function (): void {
    $cloud = new Cloud()
        ->answering(AZURE_ASKED, 200, '{"count": 1, "value": "github-jwt"}')
        ->answering(AZURE_ENTRA, 200, '{"token_type": "Bearer", "access_token": "storage-token"}');
    $credential = FederatedCredential::from(Variables::of(azureGitHub()), $cloud->exchange());
    $tokens = $credential instanceof FederatedCredential ? [$credential->token(), $credential->token()] : [];
    parse_str($cloud->requests[1]['body'] ?? '', $exchanged);

    expect($tokens)->toEqual([Token::bearer('storage-token'), Token::bearer('storage-token')])
        ->and($cloud->requests)->toHaveCount(2)
        ->and([$cloud->requests[0]['method'], $cloud->requests[0]['headers']['authorization']])->toBe(['GET', 'Bearer request-token'])
        ->and([$cloud->requests[1]['method'], $cloud->requests[1]['headers']['content-type']])->toBe(['POST', 'application/x-www-form-urlencoded'])
        ->and($exchanged)->toBe([
            'client_id' => '00000000-0000-0000-0000-000000000001',
            'scope' => 'https://storage.azure.com/.default',
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => 'github-jwt',
        ]);
});

it('asks GitHub with a query of its own where the request URL has none, and keeps a tenant in its path segment', function (): void {
    $cloud = new Cloud()
        ->answering('https://token.example/oidc?audience=api%3A%2F%2FAzureADTokenExchange', 200, '{"value": "github-jwt"}')
        ->answering('https://login.microsoftonline.com/a%2F..%3Fb/oauth2/v2.0/token', 200, '{"access_token": "storage-token"}');
    $credential = FederatedCredential::from(Variables::of([...azureGitHub('https://token.example/oidc'), 'AZURE_TENANT_ID' => 'a/..?b']), $cloud->exchange());

    expect($credential instanceof FederatedCredential ? $credential->token() : $credential)->toEqual(Token::bearer('storage-token'));
});

it('hands over a ready token, asking nothing, though GitHub\'s could be asked too', function (): void {
    $cloud = new Cloud();
    $credential = FederatedCredential::from(Variables::of([...azureGitHub(), 'MUTATION_GATE_AZURE_TOKEN' => 'eyJ.ready']), $cloud->exchange());

    expect($credential instanceof FederatedCredential ? $credential->token() : $credential)->toEqual(Token::bearer('eyJ.ready'))
        ->and($cloud->requests)->toBe([]);
});

it('says why there is no token where GitHub or Entra ID refuses, keeping it once', function (): void {
    $github = new Cloud()->answering(AZURE_ASKED, 403, 'no id-token permission');
    $entra = new Cloud()->answering(AZURE_ASKED, 200, '{"value": "jwt"}')->answering(AZURE_ENTRA, 400, 'AADSTS70021: No matching federated identity record found');
    $refusedByGitHub = FederatedCredential::from(Variables::of(azureGitHub()), $github->exchange());
    $refusedByEntra = FederatedCredential::from(Variables::of(azureGitHub()), $entra->exchange());

    expect($refusedByGitHub instanceof FederatedCredential ? [$refusedByGitHub->token(), $refusedByGitHub->token()] : [])->toEqual([
        CannotJudge::because('https://pipelines.actions.githubusercontent.com answered 403: no id-token permission'),
        CannotJudge::because('https://pipelines.actions.githubusercontent.com answered 403: no id-token permission'),
    ])
        ->and($github->requests)->toHaveCount(1)
        ->and($refusedByEntra instanceof FederatedCredential ? $refusedByEntra->token() : $refusedByEntra)
        ->toEqual(CannotJudge::because('https://login.microsoftonline.com answered 400: AADSTS70021: No matching federated identity record found'));
});

it('says there is no token where neither GitHub\'s, with the tenant and client, nor a ready one is set', function (): void {
    expect(FederatedCredential::from(Variables::of([...azureGitHub(), 'AZURE_CLIENT_ID' => '']), new Cloud()->exchange()))
        ->toEqual(Invalid::because(Problem::at(
            '',
            'neither GitHub\'s OIDC token with AZURE_TENANT_ID and AZURE_CLIENT_ID, nor MUTATION_GATE_AZURE_TOKEN, is set for the azure store.',
        )));
});
