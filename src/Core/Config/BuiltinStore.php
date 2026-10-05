<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Proof\Credentials;
use NightWorksIO\MutationGate\Core\Proof\StoreVariable;

/** The proof stores this package builds in, by the name a config chooses each by. */
enum BuiltinStore: string
{
    case Directory = 'directory';

    case S3 = 's3';

    case Gcs = 'gcs';

    case Azure = 'azure';

    public function named(): Name
    {
        return Name::of($this->value);
    }

    /**
     * The environment variables the store reads its credentials from (ADR-0007 decision 5), and nothing else,
     * those it needs to write first: none for a directory.
     *
     * @return Listed<string>
     */
    public function variables(): Listed
    {
        return $this->credentials()->variables();
    }

    /**
     * What the store needs to write, and reads besides (ADR-0007 decision 5): nothing for a directory; for S3,
     * the access key's id and secret, and a session token and a role to assume where they are set; for Cloud
     * Storage and Azure, what their federation reads, or a ready token (ADR-0028 decision 2).
     */
    public function credentials(): Credentials
    {
        return match ($this) {
            self::Directory => Credentials::none(),
            self::S3 => Credentials::needing(StoreVariable::AwsAccessKey->value, StoreVariable::AwsSecretKey->value)
                ->reading('AWS_SESSION_TOKEN', 'AWS_ROLE_ARN'),
            self::Gcs => Credentials::needing(StoreVariable::GoogleCredentials->value)
                ->orNeeding(StoreVariable::GcsToken->value)
                ->orNeeding(
                    StoreVariable::OidcRequestUrl->value,
                    StoreVariable::OidcRequestToken->value,
                    StoreVariable::GcsProvider->value,
                    StoreVariable::GcsServiceAccount->value,
                ),
            self::Azure => Credentials::needing(
                StoreVariable::OidcRequestUrl->value,
                StoreVariable::OidcRequestToken->value,
                StoreVariable::AzureTenant->value,
                StoreVariable::AzureClient->value,
            )->orNeeding(StoreVariable::AzureToken->value),
        };
    }
}
