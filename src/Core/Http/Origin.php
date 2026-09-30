<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

use function is_int;
use function is_string;
use function parse_url;

use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

use function sprintf;

/**
 * How a report names a URL it posted to: its scheme, host and port alone.
 * Its userinfo, path and query are left out, as a service may hold its key
 * in any of them, and a report is printed to a CI's log.
 */
final readonly class Origin
{
    /** What a report says of a URL it cannot read. */
    public const string UNREAD = 'an unreadable URL';

    public static function of(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return match (true) {
            ! is_string($scheme) || ! is_string($host) || $host === '' => self::UNREAD,
            is_int($port) => sprintf('%s://%s:%d', $scheme, $host, $port),
            default => sprintf('%s://%s', $scheme, $host),
        };
    }
}
