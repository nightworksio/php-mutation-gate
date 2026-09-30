<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Alert;

use DateTimeImmutable;

use function hash_hmac;
use function sprintf;

/**
 * The webhook's `X-Mutation-Gate-Signature`: `t=<unix seconds>,sha256=<HMAC>`,
 * the HMAC-SHA256 of `<t>.<body>` under the team's secret. A receiver
 * recomputes it and rejects a `t` more than 5 minutes off its own clock, so a
 * captured post cannot be replayed (ADR-0016, decision 12).
 */
final readonly class Signature
{
    /** The header the signature travels in. */
    public const string HEADER = 'X-Mutation-Gate-Signature';

    public static function of(string $body, string $secret, DateTimeImmutable $now): string
    {
        $time = $now->getTimestamp();

        return sprintf('t=%d,sha256=%s', $time, hash_hmac('sha256', sprintf('%d.%s', $time, $body), $secret));
    }
}
