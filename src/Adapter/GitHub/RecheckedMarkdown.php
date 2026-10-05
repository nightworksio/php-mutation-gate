<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;
use function array_slice;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Report\Commented;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Report\Mutator;
use NightWorksIO\MutationGate\Core\Report\RecheckedText;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sprintf;

/**
 * The PR comment while the last run's survivors are re-checked, between its
 * planned state and the verdict (ADR-0020, decision 20): how many still
 * survive, each that does with its diff, hint and reproduce command, and
 * each the run made no mutant for again, each list as long as the verdict's.
 */
final readonly class RecheckedMarkdown
{
    private const string SURVIVING = '### Still surviving (%d)';

    private const string GONE = '### Gone (%d)';

    /** The comment, with its marker, and a link to the run where there is one. */
    public static function comment(Rechecked $rechecked, string $run): string
    {
        $surviving = $rechecked->surviving();
        $gone = $rechecked->gone();
        $blocks = [
            Markdown::MARKER,
            '## mutation-gate: survivors re-checked',
            RecheckedText::headline($rechecked),
            ...$surviving === [] ? [] : [
                sprintf(self::SURVIVING, count($surviving)),
                ...MarkdownItems::details(array_slice($surviving, 0, Commented::MOST), count($surviving)),
            ],
            ...$gone === [] ? [] : [sprintf(self::GONE, count($gone)), self::gone($gone)],
            $run === '' ? '' : sprintf(PlannedMarkdown::RUN, $run),
        ];

        return sprintf("%s\n", implode("\n\n", array_filter($blocks, static fn(string $block): bool => $block !== '')));
    }

    /**
     * The survivors made again by no mutant, one line each: place, mutator and id.
     *
     * @param list<JudgedMutant> $gone
     */
    private static function gone(array $gone): string
    {
        $lines = [];

        foreach (array_slice($gone, 0, Commented::MOST) as $judged) {
            $mutant = $judged->mutant();
            $location = $mutant->location();
            $lines[] = sprintf(
                '- %s %s %s',
                Escape::code(sprintf('%s:%d', $location->file()->value(), $location->start()->number())),
                Escape::text(Mutator::short($mutant->mutator())),
                Escape::code($mutant->id()->value()),
            );
        }

        $left = count($gone) - count($lines);

        return implode("\n\n", [implode("\n", $lines), ...$left > 0 ? [Fit::more($left)] : []]);
    }
}
