<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * How the `ci` section writes what a layer sets, as a config file and as the
 * builder's calls.
 *
 * @internal
 */
final readonly class CiCalls
{
    /** @return list<string> the builder's call that sets this text, where a layer sets it */
    public static function named(string $call, string|Absent $text): array
    {
        return $text instanceof Absent ? [] : [sprintf($call, PhpCalls::literal($text))];
    }

    /** @return list<string> the builder's call that sets `ci.trustMergedPullRequests`, where a layer sets it */
    public static function trust(bool|Absent $trust): array
    {
        return match (true) {
            $trust instanceof Absent => [],
            $trust => ['Ci::trustingMergedPullRequests()'],
            default => ['Ci::notTrustingMergedPullRequests()'],
        };
    }

    /** A path as the config writes it, where a layer sets it. */
    public static function path(PathOrigin $origin, Path|Absent $path): string|Absent
    {
        return $path instanceof Path ? $origin->written($path) : $path;
    }
}
