<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function rtrim;
use function sprintf;

/**
 * One mutant as plain text, the same in the console and in a JUnit failure:
 * where it is, its mutator, how it was judged and its id; then its diff, why
 * it stands as it does, the tests that judge it by name, what they miss, the
 * command that reproduces it and the one that explains it.
 */
final readonly class MutantText
{
    /** The line naming the tests that judge a mutant. */
    public const string JUDGED_BY = 'Judged by: %s';
    private const string INDENT = '    ';

    private const string CHANGED = ', on a changed line';

    private const string MESSAGE = 'Mutant %s: %s. %s Reproduce: %s';

    /** The first line: where it is, what made it, how it was judged and its id. */
    public static function heading(JudgedMutant|JudgedKill $judged): string
    {
        $mutant = $judged->mutant();

        return sprintf(
            '%s:%d  %s  %s%s  %s',
            $mutant->location()->file()->value(),
            $mutant->location()->start()->number(),
            Mutator::short($mutant->mutator()),
            Label::of($judged->judgement()),
            $judged->isOnChangedLine() ? self::CHANGED : '',
            $mutant->id()->value(),
        );
    }

    /**
     * One line for a tool that lists results: how it was judged, its mutator,
     * what its tests miss and how to reproduce it.
     */
    public static function message(JudgedMutant $judged): string
    {
        return sprintf(
            self::MESSAGE,
            Label::of($judged->judgement()),
            Mutator::short($judged->mutant()->mutator()),
            $judged->hint()->text(),
            $judged->reproduce(),
        );
    }

    /**
     * The whole block: its heading, then its diff, reason, judging tests by
     * the names their runner gave them, hint, and reproduce and explain
     * commands, indented.
     */
    public static function block(JudgedMutant $judged, TestNames $names): string
    {
        $reason = $judged->mutant()->reason();

        $lines = [
            ...self::diffOf($judged),
            ...$reason instanceof Reason ? [sprintf('Why: %s', $reason->text())] : [],
            ...count($judged->tests()) === 0 ? [] : [sprintf(self::JUDGED_BY, $names->listed($judged->tests()))],
            $judged->hint()->text(),
            sprintf('Reproduce: %s', $judged->reproduce()),
            sprintf('Explain: %s', $judged->explain()),
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
