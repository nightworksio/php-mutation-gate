<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Removal\Removable;
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

    /** How far the lines under a heading are indented. */
    public const string INDENT = '    ';

    /** The line that names the callee a surviving removal may be deleted with (ADR-0025, decision 12). */
    private const string REMOVABLE = 'Removable: %s()';

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
     * The whole block: its heading, then its diff, reason, the rejection
     * that killed it where a static analyser did, judging tests by the
     * names their runner gave them, hint, the callee it may be deleted
     * with, and reproduce and explain commands, indented.
     */
    public static function block(JudgedMutant $judged, TestNames $names): string
    {
        $reason = $judged->mutant()->reason();
        $finding = $judged->finding();

        $lines = [
            ...self::diffOf($judged),
            ...$reason instanceof Reason ? [sprintf('Why: %s', $reason->text())] : [],
            ...$reason instanceof Rejection ? [self::rejected($reason)] : [],
            ...count($judged->tests()) === 0 ? [] : [sprintf(self::JUDGED_BY, $names->listed($judged->tests()))],
            $judged->hint()->text(),
            ...$finding instanceof Removable ? [sprintf(self::REMOVABLE, $finding->name())] : [],
            sprintf('Reproduce: %s', $judged->reproduce()),
            sprintf('Explain: %s', $judged->explain()),
        ];

        return implode("\n", [
            self::heading($judged),
            ...array_map(static fn(string $line): string => rtrim(sprintf('%s%s', self::INDENT, $line)), $lines),
        ]);
    }

    /**
     * The line naming the analyser that rejected a mutant, and the error it
     * found, each part one plain line, since an analyser's words come from
     * the project's code and custom rules.
     */
    private static function rejected(Rejection $rejection): string
    {
        return sprintf(
            'Rejected by %s: %s: %s',
            Fit::plain($rejection->analyser()),
            Fit::plain($rejection->finding()->code()),
            Fit::plain($rejection->finding()->message()),
        );
    }

    /** @return list<string> */
    private static function diffOf(JudgedMutant $judged): array
    {
        $diff = rtrim($judged->mutant()->mutation()->diff(), "\n");

        return $diff === '' ? [] : explode("\n", $diff);
    }
}
