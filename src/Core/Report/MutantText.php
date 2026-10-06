<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;

use function rtrim;
use function sprintf;

/**
 * One mutant as plain text, the same in the console and in a JUnit failure:
 * where it is, its mutator, how it was judged and its id; then its diff, why
 * it stands as it does, the tests that judge it by name, what they miss, the
 * command that reproduces it and the one that explains it. `explain`,
 * `reproduce` and a cluster's block are built from the same pieces.
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

    /**
     * The line that names the tests that judged a mutant, by the names their
     * runner gave them (ADR-0014, decision 6); none where none are named.
     *
     * @return list<string>
     */
    public static function judgedBy(JudgedMutant|JudgedKill $judged, TestNames $names): array
    {
        $tests = $judged->tests();

        return count($tests) === 0 ? [] : [sprintf(self::JUDGED_BY, $names->listed($tests))];
    }

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

    /** Why an ignored mutant is left out: the reason its ignore gives, or, where it gives none, how it was ignored. */
    public static function ignoredBecause(JudgedMutant $judged): string
    {
        $reason = $judged->mutant()->reason();

        return $reason instanceof Reason ? $reason->text() : Label::of($judged->judgement());
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
        $lines = [
            ...self::diff($judged->mutant()),
            ...self::stands($judged),
            ...self::judgedBy($judged, $names),
            ...self::missed($judged),
            sprintf('Reproduce: %s', $judged->reproduce()),
            sprintf('Explain: %s', $judged->explain()),
        ];

        return self::indented(self::heading($judged), ...$lines);
    }

    /** A heading, and these lines under it, indented. */
    public static function indented(string $heading, string ...$lines): string
    {
        return implode("\n", [
            $heading,
            ...array_map(static fn(string $line): string => rtrim(sprintf('%s%s', self::INDENT, $line)), $lines),
        ]);
    }

    /**
     * Its diff, line by line; none for a kill a ledger proved, which keeps no diff.
     *
     * @return list<string>
     */
    public static function diff(Mutant|ProvedKill $mutant): array
    {
        $diff = $mutant instanceof Mutant ? rtrim($mutant->mutation()->diff(), "\n") : '';

        return $diff === '' ? [] : explode("\n", $diff);
    }

    /**
     * Why it stands as it does, where its record says: the reason its runner
     * gave, or the rejection of the analyser that killed it.
     *
     * @return list<string>
     */
    public static function stands(JudgedMutant|JudgedKill $judged): array
    {
        $reason = $judged->mutant()->reason();

        return match (true) {
            $reason instanceof Reason => [sprintf('Why: %s', $reason->text())],
            $reason instanceof Rejection => [self::rejected($reason)],
            default => [],
        };
    }

    /**
     * Its hint, after the reason its runner gave where its record holds one,
     * for a line that has no room for `stands`: why it was left unjudged,
     * or why an ignore leaves it out, comes first.
     */
    public static function hinted(JudgedMutant $judged): string
    {
        $reason = $judged->mutant()->reason();
        $hint = $judged->hint()->text();

        return $reason instanceof Reason ? sprintf(Fit::JOINED, $reason->text(), $hint) : $hint;
    }

    /**
     * What its tests miss, and the callee it may be deleted with where the
     * gate found one (ADR-0025, decision 12).
     *
     * @return list<string>
     */
    public static function missed(JudgedMutant|JudgedKill $judged): array
    {
        $finding = $judged instanceof JudgedMutant ? $judged->finding() : NoFinding::survivor();

        return [
            $judged->hint()->text(),
            ...$finding instanceof Removable ? [sprintf(self::REMOVABLE, $finding->name())] : [],
        ];
    }

    /** The tests that judge a unit's mutants, as a reader names them. */
    public static function judging(WholeSuite|Group|Filter $judgedBy): string
    {
        return match (true) {
            $judgedBy instanceof Group => sprintf('the tests in the group %s', $judgedBy->name()),
            $judgedBy instanceof Filter => sprintf('the tests matching %s', $judgedBy->pattern()),
            default => 'every test that covers it',
        };
    }

    /**
     * The line naming the analyser that rejected a mutant, the file its
     * finding sits in, and the error it found, each part one plain line,
     * since an analyser's words come from the project's code and custom
     * rules, and a file's name from the project.
     */
    private static function rejected(Rejection $rejection): string
    {
        return sprintf(
            'Rejected by %s in %s: %s: %s',
            Fit::plain($rejection->analyser()),
            Fit::plain($rejection->finding()->file()->value()),
            Fit::plain($rejection->finding()->code()),
            Fit::plain($rejection->finding()->message()),
        );
    }
}
