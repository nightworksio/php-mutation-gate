<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Proof\Recorded;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function rtrim;
use function sprintf;

/**
 * A mutant run again, as `reproduce` prints it: where it is and what made
 * it, its diff, what the ledger recorded and what the run found now, the
 * tests that judged it, and then what the runner printed (ADR-0004 decision 6).
 */
final readonly class ReproductionText
{
    public static function of(Recorded $recorded, WholeSuite|Group|Filter $judgedBy, Reproduction $now): string
    {
        $mutant = $recorded->mutant();
        $again = $now->mutant();
        $lines = [
            ...MutantText::diff($mutant),
            sprintf('Recorded: %s', RecordText::of($recorded)),
            ...$again instanceof Mutant ? self::found($again) : [sprintf('Now: not made. %s', $again->why()->text())],
            sprintf(MutantText::JUDGED_BY, MutantText::judging($judgedBy)),
            sprintf(sprintf('Explain: %s', JudgedMutant::EXPLAIN), $mutant->id()->value()),
        ];
        $heading = sprintf(
            '%s:%d  %s  %s',
            $mutant->location()->file()->value(),
            $mutant->location()->start()->number(),
            Mutator::short($mutant->mutator()),
            $mutant->id()->value(),
        );

        return implode("\n", [
            MutantText::indented($heading, ...$lines),
            '',
            'What the runner printed:',
            rtrim($now->printed(), "\n"),
        ]);
    }

    /**
     * What the run found now, why where the runner said, and the tests that killed it.
     *
     * @return list<string>
     */
    private static function found(Mutant $again): array
    {
        $reason = $again->reason();

        return [
            sprintf('Now: %s', $again->status()->value),
            ...$reason instanceof Reason ? [sprintf('Why: %s', $reason->text())] : [],
            ...count($again->killers()) === 0 ? [] : [sprintf('Killed by: %s', self::listed($again->killers()))],
        ];
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
