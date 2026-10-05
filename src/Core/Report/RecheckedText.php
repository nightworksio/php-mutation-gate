<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Inert;
use NightWorksIO\MutationGate\Core\Recheck\Gone;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sprintf;

/**
 * The last run's survivors run again first, as the log shows them: how many
 * still survive, then one line each, in the order they were taken, with its
 * place, its mutator and its id (ADR-0020, decision 20). What a ledger holds
 * is text from outside, so each line is plain and starts no CI command.
 */
final readonly class RecheckedText
{
    private const string HEADLINE = 'Survivors re-checked: %d of %d still %s.';

    private const string KILLED = ' %d killed now.';

    private const string GONE = ' %d gone: the run made no mutant with their id again.';

    private const string LINE = '  %s: %s:%d, %s, %s';

    /** The headline, as the comment and the log give it. */
    public static function headline(Rechecked $rechecked): string
    {
        $surviving = count($rechecked->surviving());
        $killed = $rechecked->killed();
        $gone = count($rechecked->gone());

        return sprintf(
            '%s%s%s',
            sprintf(self::HEADLINE, $surviving, count($rechecked), $surviving === 1 ? 'survives' : 'survive'),
            $killed === 0 ? '' : sprintf(self::KILLED, $killed),
            $gone === 0 ? '' : sprintf(self::GONE, $gone),
        );
    }

    /** @return list<string> the headline, then a line for each survivor re-checked */
    public static function lines(Rechecked $rechecked): array
    {
        $lines = [self::headline($rechecked)];

        foreach ($rechecked as $recheck) {
            $lines[] = self::entry($recheck, $rechecked->uncovered());
        }

        return $lines;
    }

    private static function entry(Recheck $recheck, Uncovered $uncovered): string
    {
        $now = $recheck->now();

        if ($now instanceof Gone) {
            return self::line('gone', $recheck->before());
        }

        return self::line(
            $recheck->stillSurvives($uncovered) ? 'still survives' : sprintf('now %s', Label::of($now->judgement())),
            $now,
        );
    }

    private static function line(string $what, JudgedMutant $judged): string
    {
        $mutant = $judged->mutant();
        $location = $mutant->location();

        return Inert::text(sprintf(
            self::LINE,
            $what,
            Fit::plain($location->file()->value()),
            $location->start()->number(),
            Fit::plain(Mutator::short($mutant->mutator())),
            $mutant->id()->value(),
        ));
    }
}
