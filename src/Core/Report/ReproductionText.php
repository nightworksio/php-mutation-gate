<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function count;
use function explode;
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
    private const string RECORDED = 'Recorded: %s, by %s on %s at %s';

    public static function of(Recorded $recorded, WholeSuite|Group|Filter $judgedBy, Reproduction $now): string
    {
        $mutant = $recorded->mutant();
        $again = $now->mutant();
        $lines = [
            ...$mutant instanceof Mutant ? self::diffOf($mutant) : [],
            sprintf(
                self::RECORDED,
                $mutant->status()->value,
                $recorded->proof()->run()->id(),
                $recorded->scope()->name(),
                $recorded->proof()->run()->at()->value(),
            ),
            ...$again instanceof Mutant ? self::found($again) : [sprintf('Now: not made. %s', $again->why()->text())],
            sprintf('Judged by: %s', self::judging($judgedBy)),
            sprintf(sprintf('Explain: %s', JudgedMutant::EXPLAIN), $mutant->id()->value()),
        ];

        return implode("\n", [
            sprintf(
                '%s:%d  %s  %s',
                $mutant->location()->file()->value(),
                $mutant->location()->start()->number(),
                Mutator::short($mutant->mutator()),
                $mutant->id()->value(),
            ),
            ...array_map(static fn(string $line): string => rtrim(sprintf('%s%s', MutantText::INDENT, $line)), $lines),
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

    private static function judging(WholeSuite|Group|Filter $judgedBy): string
    {
        return match (true) {
            $judgedBy instanceof Group => sprintf('the tests in the group %s', $judgedBy->name()),
            $judgedBy instanceof Filter => sprintf('the tests matching %s', $judgedBy->pattern()),
            default => 'every test that covers it',
        };
    }

    private static function listed(TestIds $tests): string
    {
        $ids = [];

        foreach ($tests as $test) {
            $ids[] = $test->value();
        }

        return implode(', ', $ids);
    }

    /** @return list<string> */
    private static function diffOf(Mutant $mutant): array
    {
        $diff = rtrim($mutant->mutation()->diff(), "\n");

        return $diff === '' ? [] : explode("\n", $diff);
    }
}
