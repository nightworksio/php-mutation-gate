<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_pop;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Triage\NotMade;
use NightWorksIO\MutationGate\Core\Triage\Outcomes;
use NightWorksIO\MutationGate\Core\Triage\Repeated;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sprintf;

/**
 * What `triage` found over its runs (ADR-0008, decision 3): each mutant that
 * varied, where it is and what made it, with the runs that gave it each
 * status and the tests that killed it in them, and its reproduce command;
 * then how many of the mutants varied.
 */
final readonly class TriageText
{
    private const string VARIED = '%d of %d %s varied over %d runs.';

    private const string STEADY = 'The %d runs made %d %s, and none varied.';

    private const string NONE = 'The runs made no mutant, so none varied.';

    private const string GAVE = '%s in %s';

    private const string KILLED_BY = '%s, by %s';

    private const string NOT_MADE = 'not made';

    public static function of(Repeated $repeated): string
    {
        $varied = $repeated->varied();
        $blocks = [];

        foreach ($varied as $one) {
            $blocks[] = self::block($one);
        }

        return implode("\n\n", [...$blocks, self::summary($repeated, count($varied))]);
    }

    private static function summary(Repeated $repeated, int $varied): string
    {
        return match (true) {
            $repeated->mutants() === 0 => self::NONE,
            $varied === 0 => sprintf(self::STEADY, $repeated->runs(), $repeated->mutants(), self::mutants($repeated)),
            default => sprintf(
                self::VARIED,
                $varied,
                $repeated->mutants(),
                self::mutants($repeated),
                $repeated->runs(),
            ),
        };
    }

    private static function mutants(Repeated $repeated): string
    {
        return $repeated->mutants() === 1 ? 'mutant' : 'mutants';
    }

    private static function block(Outcomes $outcomes): string
    {
        $mutant = $outcomes->mutant();
        $lines = [];

        foreach ($outcomes->grouped() as [$outcome, $runs, $killers]) {
            $lines[] = self::gave($outcome, $runs, $killers);
        }

        return MutantText::indented(
            sprintf(
                '%s:%d  %s  %s',
                $mutant->location()->file()->value(),
                $mutant->location()->start()->number(),
                Mutator::short($mutant->mutator()),
                $mutant->id()->value(),
            ),
            ...$lines,
            ...[sprintf('Reproduce: %s', sprintf(JudgedMutant::REPRODUCE, $mutant->id()->value()))],
        );
    }

    /** @param list<int> $runs */
    private static function gave(Mutant|NotMade $outcome, array $runs, TestIds $killers): string
    {
        $gave = sprintf(
            self::GAVE,
            $outcome instanceof Mutant ? $outcome->status()->value : self::NOT_MADE,
            self::runs($runs),
        );

        return count($killers) === 0 ? $gave : sprintf(self::KILLED_BY, $gave, self::listed($killers));
    }

    /** @param list<int> $runs `run 2`, or `runs 1, 3 and 5` */
    private static function runs(array $runs): string
    {
        if (count($runs) === 1) {
            return sprintf('run %d', $runs[0]);
        }

        $last = array_pop($runs);

        return sprintf('runs %s and %d', implode(', ', $runs), $last);
    }

    private static function listed(TestIds $tests): string
    {
        $ids = [];

        foreach ($tests as $test) {
            $ids[] = $test->value();
        }

        return implode(', ', $ids);
    }
}
