<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use function is_string;

use NightWorksIO\MutationGate\Core\Doctor\AnonymousReadsRefused;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\NotGiven;

use function rtrim;
use function sprintf;

/**
 * Whether the account of the `azure` store's public container refuses
 * anonymous reads. An anonymous request that names no service version, as
 * this one does, is answered 409 by an account whose
 * `AllowBlobPublicAccess` is off, whatever the container's access level,
 * and otherwise with something else (ADR-0028 decision 4).
 */
final readonly class PublicAccess
{
    /** How an account that refuses anonymous reads answers a request of a version before the bearer challenge. */
    private const int REFUSED = 409;

    private const string CONTAINER = '%s?restype=container';

    /** The account that refused, where the options name a public URL and its account refuses anonymous reads. */
    public static function refused(Exchange $exchange, ContainerOptions $options): AnonymousReadsRefused|NotGiven
    {
        $url = $options->publicUrl();
        $answer = is_string($url) ? $exchange->answer(Request::get(sprintf(self::CONTAINER, rtrim($url, '/')))) : $url;

        return is_string($url) && $answer instanceof Reply && $answer->status() === self::REFUSED
            ? AnonymousReadsRefused::by($options->account(), $url)
            : NotGiven::value();
    }
}
