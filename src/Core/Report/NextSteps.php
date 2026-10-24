<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Sample\Estimate;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Verdict\Counts;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * What has to happen for a run to pass, a sentence each, in the JSON
 * report's `next` and so in every report rendered from it (ADR-0009): what
 * keeps the gate from judging, then for each set below its floor how many
 * more mutants its tests must catch, then each set a sample left undecided,
 * then the failures no floor decides. A run that passes needs nothing.
 */
final readonly class NextSteps
{
    /** What a run that passes needs. */
    public const string NOTHING = 'Nothing: every part of the code reaches its floor.';

    private const string OBSTACLES = 'Fix what keeps the gate from judging, said above, then run it again.';

    private const string SHORT = <<<'TEXT'
        Catch %d more of the mutants in %s to bring its score up to its floor of %s: each escaped or
        untested one says what test would catch it.
        TEXT;

    private const string ESTIMATED_SHORT
        = 'Catch more of the escaped and untested mutants in %s: its sampled score is below its floor of %s.';

    private const string UNDECIDED = 'Run %s in full, or with a smaller sampling.margin, to decide it.';

    private const string FAILURES = 'Fix each failure said above: no floor decides it.';

    /** @return list<string> */
    public static function of(Verdict $verdict): array
    {
        $steps = count($verdict->obstacles()) > 0 ? [self::OBSTACLES] : [];

        foreach ($verdict->trees() as $tree) {
            $steps = [...$steps, ...self::tree($tree)];
        }

        foreach ($verdict->sets()->newCode() as $set) {
            $steps = [...$steps, ...self::set(SetText::newCodeName($set), $set)];
        }

        foreach ($verdict->sets()->security() as $set) {
            $steps = [...$steps, ...self::set(SetText::securityName($set), $set)];
        }

        $steps = count($verdict->failures()) > 0 ? [...$steps, self::FAILURES] : $steps;

        return $steps === [] ? [self::NOTHING] : $steps;
    }

    /** @return list<string> */
    private static function tree(TreeVerdict $tree): array
    {
        $name = Fit::plain($tree->tree()->path()->value());
        $floor = $tree->floor();

        return match (true) {
            $tree->judgement() === Judgement::Undecided => [sprintf(self::UNDECIDED, $name)],
            $tree->judgement() !== Judgement::Failed || ! $floor instanceof Floor => [],
            self::sampled($tree) => [sprintf(self::ESTIMATED_SHORT, $name, Percent::of($floor))],
            default => self::short($name, $floor, $tree->counts(), $tree->uncovered(), $tree->judgement()),
        };
    }

    private static function sampled(TreeVerdict $tree): bool
    {
        $estimate = $tree->estimate();

        return $estimate instanceof Estimate && $estimate->isSampled();
    }

    /** @return list<string> */
    private static function set(string $name, NewCodeVerdict|SecurityVerdict $set): array
    {
        return self::short($name, $set->floor(), $set->counts(), $set->uncovered(), $set->judgement());
    }

    /** @return list<string> how many more mutants a set below its floor must catch; nothing for any other */
    private static function short(
        string $name,
        Floor|Exempt|Undeclared $floor,
        Counts $counts,
        Uncovered $uncovered,
        Judgement $judgement,
    ): array {
        if ($judgement !== Judgement::Failed || ! $floor instanceof Floor) {
            return [];
        }

        return [Fit::plain(sprintf(self::SHORT, $counts->shortOf($floor, $uncovered), $name, Percent::of($floor)))];
    }
}
