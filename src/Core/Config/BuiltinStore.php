<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Proof\Credentials;

/** The proof stores this package builds in, by the name a config chooses each by. */
enum BuiltinStore: string
{
    case Directory = 'directory';

    case S3 = 's3';

    public function named(): Name
    {
        return Name::of($this->value);
    }

    /**
     * The environment variables the store reads its credentials from (ADR-0007 decision 5), and nothing else:
     * none for a directory.
     *
     * @return Listed<string>
     */
    public function variables(): Listed
    {
        return match ($this) {
            self::Directory => Listed::of(),
            self::S3 => Listed::of('AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'AWS_ROLE_ARN'),
        };
    }

    /**
     * What the store needs to write (ADR-0007 decision 5): nothing for a directory; for S3, the access key's
     * id and secret.
     */
    public function credentials(): Credentials
    {
        return match ($this) {
            self::Directory => Credentials::none(),
            self::S3 => Credentials::of('AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'),
        };
    }
}
