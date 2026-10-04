<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function count;

use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

use function sprintf;

/**
 * The ignores a proof makes redundant (ADR-0013, decision 12): each entry
 * that leaves mutants out, every one of them a survivor proven equivalent.
 * It still leaves them out, and a notice says it can go. It never fails the
 * run, so a PHP whose optimizer proves less cannot turn a clean run red.
 */
final readonly class RedundantIgnores
{
    private const string REDUNDANT = 'The ignore of %s leaves out only mutants proven equivalent: this ignore can go.';

    /** @param list<Ignored> $applying the entries that still apply */
    public static function among(array $applying, TreeVerdicts $verdicts, MutantIds $proven): Warnings
    {
        $notices = Warnings::none();

        foreach ($applying as $entry) {
            $left = self::leftOut($entry, $verdicts);
            $redundant = count($left) > 0 && count($left->without($proven)) === 0;
            $notices = $redundant ? $notices->with(Warning::that(sprintf(self::REDUNDANT, $entry->named()))) : $notices;
        }

        return $notices;
    }

    /** The mutants an entry names that an ignore leaves out. */
    private static function leftOut(Ignored $entry, TreeVerdicts $verdicts): MutantIds
    {
        $left = MutantIds::none();

        foreach ($verdicts as $verdict) {
            foreach ($verdict->mutants() as $judged) {
                $leaves = $judged instanceof JudgedMutant
                    && $judged->judgement() === MutantJudgement::Ignored
                    && $entry->matches($judged->mutant());
                $left = $leaves ? $left->and(MutantIds::of($judged->mutant()->id())) : $left;
            }
        }

        return $left;
    }
}
