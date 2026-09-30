<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;
use function array_slice;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Report\Escape;

use function sprintf;

/**
 * The PR comment's planned state, which the plan job posts and the verdict
 * replaces: the units the run mutates and in how many shards, what it is
 * expected to take, and the changed lines no test covers, each list as long
 * as the verdict's (ADR-0009, decision 3, and ADR-0019, decision 11).
 */
final readonly class PlannedMarkdown
{
    private const string MUTATING = 'Mutating %s in %s: about %s wall, %s runner time (%d%% measured).';

    private const string NOTHING
        = 'Nothing to mutate: the change reaches no unit, or a proof covers every unit it reaches.';

    private const string UNITS = '<details><summary>Units (%d)</summary>';

    private const string UNCOVERED = '### Changed lines no test covers (%d)';

    private const string RUN = '[The run](%s) replaces this with its verdict.';

    /** The comment, with its marker, and a link to the run where there is one. */
    public static function comment(PlannedWork $work, string $run): string
    {
        $blocks = [
            Markdown::MARKER,
            '## mutation-gate: planned',
            self::mutating($work),
            self::units($work),
            self::uncovered($work),
            $run === '' ? '' : sprintf(self::RUN, $run),
        ];

        return sprintf("%s\n", implode("\n\n", array_filter($blocks, static fn(string $block): bool => $block !== '')));
    }

    private static function mutating(PlannedWork $work): string
    {
        $estimate = $work->estimate();
        $units = count($work->units());

        return $units === 0 ? self::NOTHING : sprintf(
            self::MUTATING,
            self::counted($units, 'unit'),
            self::counted($work->shards(), 'shard'),
            $estimate->wall()->text(),
            $estimate->runner()->text(),
            $work->measured()->wholePercent(),
        );
    }

    /** The units as a folded list; nothing where there are none. */
    private static function units(PlannedWork $work): string
    {
        $units = [];

        foreach ($work->units() as $unit) {
            $units[] = sprintf('- %s', Escape::code($unit->path()->value()));
        }

        return $units === [] ? '' : implode("\n\n", [
            sprintf(self::UNITS, count($units)),
            ...self::listed($units),
            '</details>',
        ]);
    }

    /** The uncovered changed lines under their heading; nothing where there are none. */
    private static function uncovered(PlannedWork $work): string
    {
        $lines = [];

        foreach ($work->uncovered() as $path => $numbers) {
            foreach ($numbers as $line) {
                $lines[] = sprintf('- %s', Escape::code(sprintf('%s:%d', $path->value(), $line->number())));
            }
        }

        return $lines === [] ? '' : implode("\n\n", [sprintf(self::UNCOVERED, count($lines)), ...self::listed($lines)]);
    }

    /**
     * The first entries as one list, and how many more there are.
     *
     * @param  list<string> $entries
     * @return list<string>
     */
    private static function listed(array $entries): array
    {
        $left = count($entries) - Markdown::COMMENTED;

        return [
            implode("\n", array_slice($entries, 0, Markdown::COMMENTED)),
            ...$left > 0 ? [Fit::more($left)] : [],
        ];
    }

    /** A count and what it counts, one or many. */
    private static function counted(int $count, string $one): string
    {
        return sprintf($count === 1 ? '%d %s' : '%d %ss', $count, $one);
    }
}
