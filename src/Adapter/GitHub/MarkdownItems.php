<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Report\MutantText;
use NightWorksIO\MutationGate\Core\Report\Mutator;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function rtrim;
use function sprintf;

/**
 * The mutants and clusters of a comment or step summary as GitHub Markdown:
 * as folded blocks, or as rows of a table, then how many more there are; and
 * the ignored mutants as a table of their own.
 */
final readonly class MarkdownItems
{
    /**
     * The most characters of one diff or one hint an entry shows, the rest cut, so one long line of the project's
     * code cannot fill a comment on its own.
     */
    public const int SHOWN_CHARACTERS = 1_500;

    private const string MORE = 'And %d more; the JSON report lists every one.';

    /**
     * Each mutant as a folded block with its diff, hint and reproduce
     * command, and each cluster as one with its members' diffs, one hint and
     * its stub command.
     *
     * @param  list<JudgedMutant|Cluster> $items
     * @return list<string>
     */
    public static function details(array $items, int $of): array
    {
        $blocks = [];

        foreach ($items as $item) {
            $blocks[] = $item instanceof Cluster ? self::clusterDetails($item) : implode("\n\n", [
                sprintf(
                    '<details><summary>%s %s, %s</summary>',
                    self::place($item),
                    Escape::text(Mutator::short($item->mutant()->mutator())),
                    Label::of($item->judgement()),
                ),
                self::diff($item),
                Escape::text(self::cut($item->hint()->text())),
                Escape::code($item->reproduce()),
                '</details>',
            ]);
        }

        return [...$blocks, ...self::more($of - count($items))];
    }

    /**
     * Mutants as a table: where, mutator, judgement, why and how to
     * reproduce; a cluster as one row, with its size where the mutator goes
     * and its stub command. With none of them shown, only how many were left
     * out, and nothing where there were none.
     *
     * @param  list<JudgedMutant|Cluster> $items
     * @return list<string>
     */
    public static function table(array $items, int $of): array
    {
        if ($items === []) {
            return self::more($of);
        }

        $rows = ['| Mutant | Mutator | Judgement | What the tests miss | Command |', '|---|---|---|---|---|'];

        foreach ($items as $item) {
            $rows[] = $item instanceof Cluster ? self::clusterRow($item) : self::row($item);
        }

        return [implode("\n", $rows), ...self::more($of - count($items))];
    }

    /**
     * Ignored mutants as a table: where, mutator, the gate's id and why it is
     * ignored (ADR-0008, decision 4).
     *
     * @param  list<JudgedMutant> $mutants
     * @return list<string>
     */
    public static function ignored(array $mutants, int $of): array
    {
        if ($mutants === []) {
            return [];
        }

        $rows = ['| Mutant | Mutator | Id | Why it is ignored |', '|---|---|---|---|'];

        foreach ($mutants as $judged) {
            $mutant = $judged->mutant();
            $rows[] = sprintf(
                '| %s | %s | %s | %s |',
                self::place($judged),
                Escape::text(Mutator::short($mutant->mutator())),
                Escape::code($mutant->id()->value()),
                Escape::text(MutantText::ignoredBecause($judged)),
            );
        }

        return [implode("\n", $rows), ...self::more($of - count($mutants))];
    }

    private static function clusterDetails(Cluster $cluster): string
    {
        $diffs = [];

        foreach ($cluster->members() as $member) {
            $diffs[] = self::diff($member);
        }

        return implode("\n\n", [
            sprintf(
                '<details><summary>%s %s</summary>',
                self::place($cluster->representative()),
                Escape::text(ClusterText::size($cluster)),
            ),
            ...$diffs,
            Escape::text(ClusterText::hint($cluster)),
            Escape::code($cluster->stub()),
            '</details>',
        ]);
    }

    /** A mutant's diff as a block of code, cut to the characters one entry shows. */
    private static function diff(JudgedMutant $judged): string
    {
        return Escape::block(self::cut(rtrim($judged->mutant()->mutation()->diff(), "\n")), 'diff');
    }

    /** Text cut to the characters one entry shows of it. */
    private static function cut(string $text): string
    {
        return Fit::line($text, self::SHOWN_CHARACTERS);
    }

    private static function row(JudgedMutant $judged): string
    {
        $mutant = $judged->mutant();

        return sprintf(
            '| %s | %s | %s | %s | %s |',
            self::place($judged),
            Escape::text(Mutator::short($mutant->mutator())),
            Label::of($judged->judgement()),
            Escape::text(self::cut(MutantText::hinted($judged))),
            Escape::code($judged->reproduce()),
        );
    }

    private static function clusterRow(Cluster $cluster): string
    {
        return sprintf(
            '| %s | %s | %s | %s | %s |',
            self::place($cluster->representative()),
            Escape::text(ClusterText::size($cluster)),
            Label::of($cluster->representative()->judgement()),
            Escape::text(ClusterText::hint($cluster)),
            Escape::code($cluster->stub()),
        );
    }

    /** @return list<string> */
    private static function more(int $left): array
    {
        return $left > 0 ? [sprintf(self::MORE, $left)] : [];
    }

    /** Where a mutant is, as code: its file and line. */
    private static function place(JudgedMutant $judged): string
    {
        $location = $judged->mutant()->location();

        return Escape::code(sprintf('%s:%d', $location->file()->value(), $location->start()->number()));
    }
}
