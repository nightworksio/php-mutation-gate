<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;
use function intdiv;

use NightWorksIO\MutationGate\Core\Pruning\PruningAccount;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function number_format;
use function sprintf;

/**
 * What pruning did, in the line the console, the step summary and the pull
 * request comment show under the run's headline (ADR-0025, decision 4):
 * `Pruned: 4 mutators on 212 units; 3,180 mutants carried from runs of the
 * last 7 days.` Nothing where the run pruned nothing.
 */
final readonly class PruningText
{
    private const string LINE = 'Pruned: %s on %s; %s carried from runs of the last %s.';

    public static function of(PruningAccount $pruning): string
    {
        return $pruning->isNone() ? '' : sprintf(
            self::LINE,
            self::counted(count($pruning->mutators()), 'mutator'),
            self::counted($pruning->units(), 'unit'),
            self::counted($pruning->carried(), 'mutant'),
            self::since((int) $pruning->audit()->seconds()),
        );
    }

    /** A count and its noun, a thousands separator in the count, the noun plural but for one. */
    private static function counted(int $count, string $noun): string
    {
        return sprintf('%s %s%s', number_format($count), $noun, $count === 1 ? '' : 's');
    }

    /** How far back a carried result may come from: whole days, or the duration as a report says it. */
    private static function since(int $seconds): string
    {
        $days = intdiv($seconds, Seconds::PER_DAY);

        return match (true) {
            $days === 1 && $seconds % Seconds::PER_DAY === 0 => 'day',
            $days > 1 && $seconds % Seconds::PER_DAY === 0 => sprintf('%d days', $days),
            default => Seconds::of((float) $seconds)->text(),
        };
    }
}
