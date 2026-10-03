<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * The environment variables the `gcs` and `azure` stores read their tokens
 * from (ADR-0028 decision 2), and nothing else.
 */
enum StoreVariable: string
{
    /** The external-account credentials file `google-github-actions/auth` writes. */
    case GoogleCredentials = 'GOOGLE_APPLICATION_CREDENTIALS';

    /** A ready bearer token for Cloud Storage, from another CI's federation. */
    case GcsToken = 'MUTATION_GATE_GCS_TOKEN';

    /** Where a GitHub Actions job asks for its OIDC token. */
    case OidcRequestUrl = 'ACTIONS_ID_TOKEN_REQUEST_URL';

    /** What a GitHub Actions job asks for its OIDC token with. */
    case OidcRequestToken = 'ACTIONS_ID_TOKEN_REQUEST_TOKEN';

    /** The Microsoft Entra tenant whose federated credential trusts the job, as `azure/login` reads it. */
    case AzureTenant = 'AZURE_TENANT_ID';

    /** The app registration or managed identity the job's token is exchanged for. */
    case AzureClient = 'AZURE_CLIENT_ID';

    /** A ready bearer token for Azure Storage, from another CI's federation. */
    case AzureToken = 'MUTATION_GATE_AZURE_TOKEN';
}
