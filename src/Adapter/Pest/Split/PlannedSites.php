<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * The sites a split stream's plan names, read from the JSON Pest's own
 * process wrote (ADR-0117): for each file, each site's mutant, the test that
 * kills it and the variables its run sets, by the site's id; a site not as
 * written left out.
 *
 * @internal
 */
final readonly class PlannedSites
{
    /** Where a site lists the variables its run sets. */
    private const int VARIABLES = 2;

    /**
     * @param  array<mixed>                                                            $sites
     * @return array<string, array<int, array{string, string, array<string, string>}>>
     */
    public static function of(array $sites): array
    {
        $read = [];

        foreach ($sites as $file => $switches) {
            $read[(string) $file] = is_array($switches) ? self::switchesOf($switches) : [];
        }

        return $read;
    }

    /**
     * @param  array<mixed>                                             $switches
     * @return array<int, array{string, string, array<string, string>}>
     */
    private static function switchesOf(array $switches): array
    {
        $read = [];

        foreach ($switches as $id => $site) {
            $planned = is_array($site) ? self::siteOf($site) : false;

            if ($planned !== false) {
                $read[(int) $id] = $planned;
            }
        }

        return $read;
    }

    /**
     * @param  array<mixed>                                       $site
     * @return array{string, string, array<string, string>}|false
     */
    private static function siteOf(array $site): array|false
    {
        $mutant = self::textAt($site, 0);
        $killer = self::textAt($site, 1);
        $variables = array_key_exists(self::VARIABLES, $site) && is_array($site[self::VARIABLES])
            ? $site[self::VARIABLES]
            : [];

        return $mutant !== false && $killer !== false
            ? [$mutant, $killer, StreamPlanFile::texts($variables)]
            : false;
    }

    /** @param array<mixed> $read */
    private static function textAt(array $read, int $at): string|false
    {
        return array_key_exists($at, $read) && is_string($read[$at]) ? $read[$at] : false;
    }
}
