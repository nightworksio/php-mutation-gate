<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function rtrim;
use function sprintf;

/**
 * One mutant as plain text, the same in the console and in a JUnit failure:
 * where it is, its mutator, how it was judged and its id; then its diff, why
 * it stands as it does, the tests that judge it, what they miss, and the
 * command that reproduces it.
 */
final readonly class MutantText
{
    private const string INDENT = '    ';

    private const string CHANGED = ', on a changed line';

    /** The first line: where it is, what made it, how it was judged and its id. */
    public static function heading(JudgedMutant $judged): string
    {
        $mutant = $judged->mutant();

        return sprintf(
            '%s:%d  %s  %s%s  %s',
            $mutant->location()->file()->value(),
            $mutant->location()->start()->number(),
            Mutator::short($mutant->mutation()->mutator()),
            Label::of($judged->judgement()),
            $judged->isOnChangedLine() ? self::CHANGED : '',
            $mutant->id()->value(),
        );
    }

    /** The whole block: its heading, then its diff, reason, hint and reproduce command, indented. */
    public static function block(JudgedMutant $judged): string
    {
        $reason = $judged->mutant()->reason();
        $tests = [];

        foreach ($judged->tests() as $test) {
            $tests[] = $test->value();
        }

        $lines = [
            ...self::diffOf($judged),
            ...$reason instanceof Reason ? [sprintf('Why: %s', $reason->text())] : [],
            ...$tests === [] ? [] : [sprintf('Judged by: %s', implode(', ', $tests))],
            $judged->hint()->text(),
            sprintf('Reproduce: %s', $judged->reproduce()),
        ];

        return implode("\n", [
            self::heading($judged),
            ...array_map(static fn(string $line): string => rtrim(sprintf('%s%s', self::INDENT, $line)), $lines),
        ]);
    }

    /** @return list<string> */
    private static function diffOf(JudgedMutant $judged): array
    {
        $diff = rtrim($judged->mutant()->mutation()->diff(), "\n");

        return $diff === '' ? [] : explode("\n", $diff);
    }
}
