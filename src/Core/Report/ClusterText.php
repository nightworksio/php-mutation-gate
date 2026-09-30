<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;

use function rtrim;
use function sprintf;

/**
 * One cluster as plain text, for the console: where its first member is,
 * how many it holds, by which rule and its id; then each member's heading
 * and diff, why one test may kill them all, what the tests miss, and the
 * commands that write that test and explain the cluster.
 */
final readonly class ClusterText
{
    /** The first line: where it is, how many it holds, by which rule, and its id. */
    public static function heading(Cluster $cluster): string
    {
        return sprintf('%s  %s', self::summary($cluster), $cluster->id()->value());
    }

    /** Where its first member is, how many it holds and by which rule, as `src/Money.php:12  3 survivors, one gap`. */
    public static function summary(Cluster $cluster): string
    {
        $location = $cluster->representative()->mutant()->location();

        return sprintf(
            '%s:%d  %s',
            $location->file()->value(),
            $location->start()->number(),
            self::size($cluster),
        );
    }

    /** How many it holds and by which rule, as `3 survivors, one expression`. */
    public static function size(Cluster $cluster): string
    {
        return sprintf('%d survivors, %s', count($cluster->members()), $cluster->kind()->label());
    }

    /** Why one test may kill them all, then what the tests miss of its first member. */
    public static function hint(Cluster $cluster): string
    {
        return sprintf('%s %s', $cluster->kind()->text(), $cluster->representative()->hint()->text());
    }

    /** The whole block: its heading, then each member with its diff, the hint, and the stub and explain commands. */
    public static function block(Cluster $cluster): string
    {
        $lines = [];

        foreach ($cluster->members() as $member) {
            $lines[] = MutantText::heading($member);
            $diff = rtrim($member->mutant()->mutation()->diff(), "\n");

            foreach ($diff === '' ? [] : explode("\n", $diff) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = self::hint($cluster);
        $lines[] = sprintf('Stub: %s', $cluster->stub());
        $lines[] = sprintf('Explain: %s', $cluster->explain());

        return implode("\n", [
            self::heading($cluster),
            ...array_map(static fn(string $line): string => rtrim(sprintf('%s%s', MutantText::INDENT, $line)), $lines),
        ]);
    }
}
