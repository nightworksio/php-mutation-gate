<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function implode;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;

use function sprintf;

/**
 * What `pre-commit` prints, and `pre-push` before its verdict (ADR-0015,
 * decisions 10 to 12): each tree the change reaches, with its score against
 * its floor and its change against the base, or that it is not measured yet;
 * how many reached units have no local result for what is on disk; and how
 * many of their files hold changes that are not staged, which the scores
 * include.
 */
final readonly class ScoreChangeText
{
    private const string NOTHING_REACHED = 'The change reaches no tree.';

    private const string UNMEASURED = '%s is not measured yet: a unit of it has no result.';

    private const string UNJUDGED = '%d %s unjudged since your last run: mutation-gate';

    private const string UNSTAGED = 'The scores include unstaged changes in %d %s.';

    private const string UNMAPPED = <<<'SAID'
        No local run has left a coverage map, so the gate cannot tell which results are for what is on disk,
        and shows no score. Run mutation-gate once.
        SAID;

    /**
     * @param TreeVerdicts $reached    each tree the change reaches whose every unit has a result
     * @param Paths        $unmeasured each tree the change reaches with a unit that has none
     * @param int          $unjudged   how many reached units have no local result for what is on disk
     * @param int          $unstaged   how many files of the reached units hold changes that are not staged
     */
    public static function of(TreeVerdicts $reached, Paths $unmeasured, int $unjudged, int $unstaged): string
    {
        $lines = [];

        foreach ($reached as $tree) {
            $lines[] = SetText::tree($tree);
        }

        foreach ($unmeasured as $tree) {
            $lines[] = sprintf(self::UNMEASURED, $tree->value());
        }

        $trees = $lines === [] ? [self::NOTHING_REACHED] : $lines;

        return implode("\n", [...$trees, ...self::counted($unjudged, $unstaged)]);
    }

    /** Why no score is shown where no local run has left a coverage map. */
    public static function unmapped(): string
    {
        return self::UNMAPPED;
    }

    /**
     * The lines that count the reached units with no local result and the files with unstaged changes, where any.
     *
     * @return list<string>
     */
    private static function counted(int $unjudged, int $unstaged): array
    {
        return [
            ...$unjudged > 0 ? [sprintf(self::UNJUDGED, $unjudged, $unjudged === 1 ? 'unit' : 'units')] : [],
            ...$unstaged > 0 ? [sprintf(self::UNSTAGED, $unstaged, $unstaged === 1 ? 'file' : 'files')] : [],
        ];
    }
}
