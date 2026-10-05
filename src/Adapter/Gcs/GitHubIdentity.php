<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Gcs;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Http\TokenField;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\StoreVariable;

use function preg_match;
use function rawurlencode;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * A GitHub Actions job's own federation with Cloud Storage (ADR-0028 decision 2): its OIDC token, which `id-token:
 * write` lets it ask for, exchanged through the workload identity provider `MUTATION_GATE_GCS_PROVIDER` names and
 * impersonating the service account `MUTATION_GATE_GCS_SERVICE_ACCOUNT` names, as `google-github-actions/auth` sets
 * it up, so no third-party action runs in a job that holds the store's keys. The token is asked for the provider's
 * default audience, `https://iam.googleapis.com/<provider>`.
 */
final readonly class GitHubIdentity
{
    /** A provider's full name, numbered by its project, with no part that climbs. */
    private const string PROVIDER
        = '#^projects/\d+/locations/global/workloadIdentityPools/[a-z0-9-]+/providers/[a-z0-9-]+$#';

    /** A service account's email, which IAM names in the URL that impersonates it. */
    private const string SERVICE_ACCOUNT = '#^[a-z0-9-]+@[a-z0-9-]+\.iam\.gserviceaccount\.com$#';

    /** The audience a provider accepts GitHub's token for unless it names its own. */
    private const string DEFAULT_AUDIENCE = 'https://iam.googleapis.com/%s';

    /** How GitHub's token request asks for an audience, after the parameters its URL holds. */
    private const string ASKED = '%s%saudience=%s';

    private const string QUERY = '?';

    private const string NOT_PROVIDER
        = '%s is not a provider, projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>.';

    private const string NOT_SERVICE_ACCOUNT = '%s is not a service account, <name>@<project>.iam.gserviceaccount.com.';

    private const string NO_OIDC
        = 'GitHub hands this job no OIDC token for the gcs store: grant the job id-token: write.';

    /** The account the environment names; nothing where it names neither part; or why it refuses what it names. */
    public static function in(Variables $environment): ExternalAccount|Invalid|NotGiven
    {
        $provider = $environment->valueOf(StoreVariable::GcsProvider->value);
        $account = $environment->valueOf(StoreVariable::GcsServiceAccount->value);
        $url = $environment->valueOf(StoreVariable::OidcRequestUrl->value);
        $token = $environment->valueOf(StoreVariable::OidcRequestToken->value);

        return match (true) {
            $provider === '' && $account === '' => NotGiven::value(),
            preg_match(self::PROVIDER, $provider) !== 1
                => self::refused(sprintf(self::NOT_PROVIDER, StoreVariable::GcsProvider->value)),
            preg_match(self::SERVICE_ACCOUNT, $account) !== 1
                => self::refused(sprintf(self::NOT_SERVICE_ACCOUNT, StoreVariable::GcsServiceAccount->value)),
            ! str_starts_with($url, ExternalAccount::HTTPS) || $token === '' => self::refused(self::NO_OIDC),
            default => ExternalAccount::federating($provider, $account, self::source($url, $token, $provider)),
        };
    }

    /** GitHub's token request for the provider's default audience, carrying the job's request token. */
    private static function source(string $url, string $token, string $provider): SubjectSource
    {
        $joined = str_contains($url, self::QUERY) ? '&' : self::QUERY;
        $audience = rawurlencode(sprintf(self::DEFAULT_AUDIENCE, $provider));
        $request = Request::get(sprintf(self::ASKED, $url, $joined, $audience))->carrying(Token::bearer($token));

        return SubjectSource::url($request, TokenField::GitHubOidc->value);
    }

    private static function refused(string $why): Invalid
    {
        return Invalid::because(Problem::at('', $why));
    }
}
