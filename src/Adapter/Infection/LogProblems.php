<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_filter;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

/**
 * What keeps Infection's logs from being read as they are: a count in
 * `stats` that does not match its list, a `totalMutantsCount` that is not
 * the sum of the counts, or mutants ignored by markers `ignores.native`
 * refuses.
 *
 * @internal
 */
final readonly class LogProblems
{
    private const string STATS = 'stats';

    private const string UNEVEN = "Infection's log counts %d under %s but lists %d.";

    private const string UNEVEN_SKIPPED = 'Infection counts %d skipped mutants but its text log names %d.';

    private const string UNEVEN_TOTAL = 'Infection counts %d mutants in all, but its counts add up to %d.';

    private const string IGNORED
        = 'Infection ignored %d mutants by an ignoreSourceCodeByRegex pattern, which ignores.native refuses.';

    /**
     * Each problem of the logs, as one text; empty where they have none.
     *
     * @throws NotInShape
     */
    public static function in(Node $log, TextLog $text, bool $nativeMarkersAllowed): string
    {
        $stats = $log->field(self::STATS);
        $problems = [];
        $sum = 0;

        foreach (LogList::cases() as $list) {
            $counted = $stats->field($list->count())->integer();
            $listed = count($log->field($list->value)->items());
            $sum += $counted;
            $problems[] = $counted === $listed ? '' : sprintf(self::UNEVEN, $counted, $list->count(), $listed);
        }

        $skipped = $stats->field('skippedCount')->integer();
        $named = $text->count(TextLog::SKIPPED);
        $total = $stats->field('totalMutantsCount')->integer();
        $ignored = $stats->field(LogList::Ignored->count())->integer();

        $problems[] = $skipped === $named ? '' : sprintf(self::UNEVEN_SKIPPED, $skipped, $named);
        $problems[] = $total === $sum + $skipped ? '' : sprintf(self::UNEVEN_TOTAL, $total, $sum + $skipped);
        $problems[] = $ignored === 0 || $nativeMarkersAllowed ? '' : sprintf(self::IGNORED, $ignored);

        return implode(' ', array_filter($problems, static fn(string $problem): bool => $problem !== ''));
    }
}
