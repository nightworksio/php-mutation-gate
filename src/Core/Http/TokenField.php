<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

/** The field of a token endpoint's JSON answer that holds the token it gives. */
enum TokenField: string
{
    /** OAuth 2.0's, as Google's security token service and Microsoft Entra ID answer. */
    case OAuth = 'access_token';

    /** Google's IAM credentials API's, answering `generateAccessToken`. */
    case Impersonated = 'accessToken';

    /** GitHub's, answering a request for an OIDC token. */
    case GitHubOidc = 'value';
}
