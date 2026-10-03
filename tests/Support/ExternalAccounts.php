<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_replace_recursive;
use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;

/** External-account credentials files, as `google-github-actions/auth` writes them, in a scratch directory. */
final class ExternalAccounts
{
    public const string AUDIENCE = '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/github/providers/gate';

    public const string REQUEST_URL = 'https://pipelines.actions.githubusercontent.com/oidc?api-version=2.0&audience=https%3A%2F%2Fiam.googleapis.com';

    public const string STS = 'https://sts.googleapis.com/v1/token';

    public const string IMPERSONATE = 'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/gate@acme.iam.gserviceaccount.com:generateAccessToken';

    /** The file, as the action writes it, with the fields of this JSON object laid over it, at a new path. */
    public static function written(string $over = '{}'): string
    {
        $fields = json_decode($over, associative: true);
        $root = Scratch::directory();
        Scratch::write($root, 'gha-creds.json', (string) json_encode(array_replace_recursive([
            'type' => 'external_account',
            'audience' => self::AUDIENCE,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
            'token_url' => self::STS,
            'credential_source' => [
                'url' => self::REQUEST_URL,
                'headers' => ['Authorization' => 'Bearer request-token'],
                'format' => ['type' => 'json', 'subject_token_field_name' => 'value'],
            ],
        ], is_array($fields) ? $fields : [])));

        return sprintf('%s/gha-creds.json', $root);
    }

    /** A file of these exact contents, at a new path. */
    public static function holding(string $contents): string
    {
        $root = Scratch::directory();
        Scratch::write($root, 'creds.json', $contents);

        return sprintf('%s/creds.json', $root);
    }
}
