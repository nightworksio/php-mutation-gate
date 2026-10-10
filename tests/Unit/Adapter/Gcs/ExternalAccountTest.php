<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Gcs\ExternalAccount;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\ExternalAccounts;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$refused = static fn(string $why): Invalid => Invalid::because(Problem::at('', $why));

it('exchanges the CI\'s token, asked as the file says, for an access token to Cloud Storage', function (): void {
    $cloud = new Cloud()
        ->answering(ExternalAccounts::REQUEST_URL, 200, '{"count": 1, "value": "github-jwt"}')
        ->answering(ExternalAccounts::STS, 200, '{"access_token": "federated", "token_type": "Bearer"}');
    $account = ExternalAccount::at(ExternalAccounts::written());
    $token = $account instanceof ExternalAccount ? $account->token($cloud->exchange()) : $account;
    parse_str($cloud->requests[1]['body'], $exchanged);

    expect($token)->toEqual(Token::bearer('federated'))
        ->and([$cloud->requests[0]['method'], $cloud->requests[0]['headers']['authorization']])->toBe(['GET', 'Bearer request-token'])
        ->and([$cloud->requests[1]['method'], $cloud->requests[1]['headers']['content-type']])->toBe(['POST', 'application/x-www-form-urlencoded'])
        ->and($exchanged)->toBe([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'audience' => ExternalAccounts::AUDIENCE,
            'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
            'requested_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'subject_token' => 'github-jwt',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
        ])
        ->and($cloud->requests)->toHaveCount(2);
});

it('impersonates the service account the file names, with the federated token', function (): void {
    $cloud = new Cloud()
        ->answering(ExternalAccounts::REQUEST_URL, 200, '{"value": "github-jwt"}')
        ->answering(ExternalAccounts::STS, 200, '{"access_token": "federated"}')
        ->answering(ExternalAccounts::IMPERSONATE, 200, '{"accessToken": "service-account", "expireTime": "2026-10-03T12:00:00Z"}');
    $account = ExternalAccount::at(ExternalAccounts::written(sprintf('{"service_account_impersonation_url": "%s"}', ExternalAccounts::IMPERSONATE)));
    $token = $account instanceof ExternalAccount ? $account->token($cloud->exchange()) : $account;
    parse_str($cloud->requests[1]['body'], $exchanged);

    expect($token)->toEqual(Token::bearer('service-account'))
        ->and($exchanged['scope'] ?? '')->toBe('https://www.googleapis.com/auth/cloud-platform')
        ->and([$cloud->requests[2]['method'], $cloud->requests[2]['url'], $cloud->requests[2]['body']])
        ->toBe(['POST', ExternalAccounts::IMPERSONATE, '{"scope":["https://www.googleapis.com/auth/devstorage.read_write"]}'])
        ->and([$cloud->requests[2]['headers']['authorization'], $cloud->requests[2]['headers']['content-type']])
        ->toBe(['Bearer federated', 'application/json']);
});

it('reads the CI\'s token from the file the source names, whole or from a JSON field', function (string $format, string $contents): void {
    $root = Scratch::directory();
    Scratch::write($root, 'oidc-token', $contents);
    $cloud = new Cloud()->answering(ExternalAccounts::STS, 200, '{"access_token": "federated"}');
    $account = ExternalAccount::at(ExternalAccounts::holding((string) json_encode([
        'type' => 'external_account',
        'audience' => ExternalAccounts::AUDIENCE,
        'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
        'token_url' => ExternalAccounts::STS,
        'credential_source' => ['file' => sprintf('%s/oidc-token', $root), 'format' => json_decode($format, associative: true)],
    ])));
    $token = $account instanceof ExternalAccount ? $account->token($cloud->exchange()) : $account;
    parse_str($cloud->requests[0]['body'], $exchanged);

    expect($token)->toEqual(Token::bearer('federated'))
        ->and($exchanged['subject_token'] ?? '')->toBe('gitlab-jwt');
})->with([
    'text' => ['{"type": "text"}', "gitlab-jwt\n"],
    'no format' => ['{}', 'gitlab-jwt'],
    'a JSON field' => ['{"type": "json", "subject_token_field_name": "id_token"}', '{"id_token": "gitlab-jwt"}'],
]);

it('says why there is no token where the CI\'s, the exchange or the impersonation fails', function (string $over, Cloud $cloud, string $why): void {
    $account = ExternalAccount::at(ExternalAccounts::written($over));

    expect($account instanceof ExternalAccount ? $account->token($cloud->exchange()) : $account)->toEqual(CannotJudge::because($why));
})->with([
    'GitHub refuses' => [
        '{}',
        fn(): Cloud => new Cloud()->answering(ExternalAccounts::REQUEST_URL, 401, 'Unauthorized'),
        'https://pipelines.actions.githubusercontent.com answered 401: Unauthorized',
    ],
    'GitHub gives none' => [
        '{}',
        fn(): Cloud => new Cloud()->answering(ExternalAccounts::REQUEST_URL, 200, '{"value": ""}'),
        'the CI\'s token from https://pipelines.actions.githubusercontent.com is empty',
    ],
    'the exchange refuses' => [
        '{}',
        fn(): Cloud => new Cloud()
            ->answering(ExternalAccounts::REQUEST_URL, 200, '{"value": "jwt"}')
            ->answering(ExternalAccounts::STS, 400, '{"error": "invalid_grant"}'),
        'https://sts.googleapis.com answered 400: {"error": "invalid_grant"}',
    ],
    'the impersonation refuses' => [
        fn(): string => sprintf('{"service_account_impersonation_url": "%s"}', ExternalAccounts::IMPERSONATE),
        fn(): Cloud => new Cloud()
            ->answering(ExternalAccounts::REQUEST_URL, 200, '{"value": "jwt"}')
            ->answering(ExternalAccounts::STS, 200, '{"access_token": "federated"}')
            ->answering(ExternalAccounts::IMPERSONATE, 403, 'denied'),
        'https://iamcredentials.googleapis.com answered 403: denied',
    ],
]);

it('says why there is no token where the file the source names cannot be read', function (): void {
    $account = ExternalAccount::at(ExternalAccounts::holding((string) json_encode([
        'type' => 'external_account',
        'audience' => ExternalAccounts::AUDIENCE,
        'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
        'token_url' => ExternalAccounts::STS,
        'credential_source' => ['file' => '/nowhere/oidc-token'],
    ])));

    expect($account instanceof ExternalAccount ? $account->token(new Cloud()->exchange()) : $account)
        ->toEqual(CannotJudge::because('the CI\'s token could not be read from /nowhere/oidc-token'));
});

it('refuses a key, and any credentials but an external account', function (string $contents, string $why) use ($refused): void {
    expect(ExternalAccount::at(ExternalAccounts::holding($contents)))->toEqual($refused($why));
})->with([
    'a service-account key' => [
        '{"type": "service_account", "private_key": "-----BEGIN PRIVATE KEY-----"}',
        'GOOGLE_APPLICATION_CREDENTIALS names a service-account key, which the gcs store refuses: use Workload Identity Federation instead.',
    ],
    'a user\'s refresh token' => [
        '{"type": "authorized_user", "refresh_token": "1//0g"}',
        'GOOGLE_APPLICATION_CREDENTIALS names "authorized_user" credentials; the gcs store reads only an external_account file, from federation.',
    ],
    'no JSON' => [
        'not json',
        'GOOGLE_APPLICATION_CREDENTIALS names "" credentials; the gcs store reads only an external_account file, from federation.',
    ],
]);

it('refuses an external account that misses a field, or sends a token anywhere but Google\'s', function (string $over, string $why) use ($refused): void {
    expect(ExternalAccount::at(ExternalAccounts::written($over)))->toEqual($refused($why));
})->with([
    'no audience' => ['{"audience": ""}', 'GOOGLE_APPLICATION_CREDENTIALS names an external_account file without audience.'],
    'no token type' => ['{"subject_token_type": ""}', 'GOOGLE_APPLICATION_CREDENTIALS names an external_account file without subject_token_type.'],
    'no exchange' => ['{"token_url": ""}', 'GOOGLE_APPLICATION_CREDENTIALS names an external_account file without token_url.'],
    'an exchange elsewhere' => [
        '{"token_url": "https://sts.example.com/v1/token"}',
        'GOOGLE_APPLICATION_CREDENTIALS names an external_account file whose token_url is not at https://sts.googleapis.com.',
    ],
    'an exchange over http' => [
        '{"token_url": "http://sts.googleapis.com/v1/token"}',
        'GOOGLE_APPLICATION_CREDENTIALS names an external_account file whose token_url is not at https://sts.googleapis.com.',
    ],
    'an impersonation elsewhere' => [
        '{"service_account_impersonation_url": "https://iam.example.com/generateAccessToken"}',
        'GOOGLE_APPLICATION_CREDENTIALS names an external_account file whose service_account_impersonation_url is not at https://iamcredentials.googleapis.com.',
    ],
    'an impersonation of nothing' => [
        '{"service_account_impersonation_url": ""}',
        'GOOGLE_APPLICATION_CREDENTIALS names an external_account file whose service_account_impersonation_url is not at https://iamcredentials.googleapis.com.',
    ],
    'a source with no host' => [
        '{"credential_source": {"url": "https:///token"}}',
        'GOOGLE_APPLICATION_CREDENTIALS names an external_account file whose credential source is neither a file nor an https URL.',
    ],
    'a source over http' => [
        '{"credential_source": {"url": "http://169.254.169.254/token"}}',
        'GOOGLE_APPLICATION_CREDENTIALS names an external_account file whose credential source is neither a file nor an https URL.',
    ],
]);

it('refuses a credential source it does not read, and a file it cannot read', function () use ($refused): void {
    $executable = ExternalAccounts::holding((string) json_encode([
        'type' => 'external_account',
        'audience' => ExternalAccounts::AUDIENCE,
        'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
        'token_url' => ExternalAccounts::STS,
        'credential_source' => ['executable' => ['command' => '/usr/bin/token']],
    ]));

    expect(ExternalAccount::at($executable))->toEqual($refused(
        'GOOGLE_APPLICATION_CREDENTIALS names an external_account file whose credential source is neither a file nor an https URL.',
    ))
        ->and(ExternalAccount::at('/nowhere/creds.json'))->toEqual($refused('GOOGLE_APPLICATION_CREDENTIALS names /nowhere/creds.json, which cannot be read.'));
});
