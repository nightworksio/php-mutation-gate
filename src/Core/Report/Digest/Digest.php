<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Report\Outcome;
use NightWorksIO\MutationGate\Core\Report\SurvivorOrder;
use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;

use function sprintf;

/**
 * The digest: a run's verdict for an agent or a terminal, rendered from the
 * JSON report as every report is, so it says the verdict, the counts and
 * where it comes from as they do (ADR-0009). One fact a line, each opened by
 * a word that never changes: `mg` the verdict and the project's score, `why`
 * a reason, `next` what has to happen to pass, `counts` how many mutants
 * came to each outcome with its grade, `set` each set's score against its
 * floor, `prov` where the verdict comes from, `warn` a warning, then each
 * survivor under the `@` line of its file, those on changed lines first, as
 * many as asked, and `more` how many it left out.
 */
final readonly class Digest
{
    /** What a value the report does not hold is written as. */
    public const string NONE = '-';
    private const string VERDICT = 'mg verdict=%s score=%s%s%s';

    private const string SAMPLED = ' sampled';

    private const string CUT_SHORT = ' cut-short';

    private const string WHY = 'why %s';

    private const string NEXT = 'next %s';

    private const string COUNT = '%s=%d(%s)';

    private const string COUNTS = 'counts %s';

    private const string WARN = 'warn %s';

    private const string FILE = '@ %s';

    private const string MORE = 'more %d: mutation-gate digest --survivors=all';

    private const string TERM = '? %s: %s';

    /** @throws NotInShape */
    public static function overview(Node $report, SurvivorsShown $shown): string
    {
        $survivors = self::survivors($report);

        return self::lines([
            sprintf(
                self::VERDICT,
                $report->field('judgement')->text(),
                Scores::percent($report->field('score')),
                $report->field('sample')->isPresent() ? self::SAMPLED : '',
                $report->field('cutShort')->boolean() ? self::CUT_SHORT : '',
            ),
            ...self::texts($report->field('why'), self::WHY),
            ...self::texts($report->field('next'), self::NEXT),
            self::counts($report->field('counts')),
            ...Scores::sets($report),
            ProvenanceLine::of($report->field('provenance')),
            ...self::texts($report->field('warnings'), self::WARN),
            ...self::grouped($shown->of($survivors)),
            ...$shown->leftOut($survivors) > 0 ? [sprintf(self::MORE, $shown->leftOut($survivors))] : [],
        ]);
    }

    /** What each word the reports use means, a line each, for `--detail=legend`. */
    public static function legend(): string
    {
        $lines = [];

        foreach (Term::cases() as $term) {
            $lines[] = sprintf(self::TERM, $term->value, $term->meaning());
        }

        return self::lines($lines);
    }

    /**
     * How many mutants came to each outcome, each with its grade.
     *
     * @throws NotInShape
     */
    public static function counts(Node $counts): string
    {
        $judged = [];

        foreach ($counts->entries() as $judgement => $number) {
            $judged[sprintf('%s', $judgement)] = $number->integer();
        }

        return sprintf(self::COUNTS, self::outcomes($judged));
    }

    /**
     * How many mutants came to each outcome, each with its grade, from how many came to each judgement.
     *
     * @param array<string, int> $judged by the judgement's name
     */
    public static function outcomes(array $judged): string
    {
        $said = [];

        $counts = Outcome::counts($judged);

        foreach ([...Outcome::counted(), Outcome::LeftOut] as $outcome) {
            $said[] = sprintf(self::COUNT, $outcome->value, $counts[$outcome->value], $outcome->grade()->value);
        }

        return implode(' ', $said);
    }

    /**
     * Mutants under the line of the file each is in, a file's line before its first.
     *
     * @param  list<DigestMutant> $mutants
     * @return list<string>
     *
     * @throws NotInShape
     */
    public static function grouped(array $mutants): array
    {
        $lines = [];
        $last = '';

        foreach ($mutants as $mutant) {
            $file = $mutant->file();

            if ($file !== $last) {
                $lines[] = sprintf(self::FILE, $file);
            }

            $lines[] = $mutant->said();
            $last = $file;
        }

        return $lines;
    }

    /**
     * Lines as the digest writes them, each one line of text with every control and format character dropped, so
     * nothing a project wrote can colour a terminal or reorder what an agent reads.
     *
     * @param list<string> $lines
     */
    public static function lines(array $lines): string
    {
        $written = [];

        foreach (explode("\n", implode("\n", $lines)) as $line) {
            $written[] = Fit::verbatim($line);
        }

        return sprintf("%s\n", implode("\n", $written));
    }

    /**
     * The mutants the score counts as not killed, those on changed lines first, then by file and line.
     *
     * @return list<DigestMutant>
     *
     * @throws NotInShape
     */
    private static function survivors(Node $report): array
    {
        $uncovered = Uncovered::from($report->field('uncovered')->text());
        $survivors = [];

        foreach ($report->field('mutants')->items() as $entry) {
            $mutant = DigestMutant::of($entry);

            if ($mutant->survives($uncovered)) {
                $survivors[] = $mutant;
            }
        }

        return SurvivorOrder::of(
            $survivors,
            static fn(DigestMutant $mutant): bool => $mutant->changed(),
            static fn(DigestMutant $mutant): array => [$mutant->file(), $mutant->line()],
        );
    }

    /**
     * Each text of a list, as one plain line opened by its word.
     *
     * @return list<string>
     *
     * @throws NotInShape
     */
    private static function texts(Node $texts, string $line): array
    {
        $lines = [];

        foreach ($texts->items() as $text) {
            $lines[] = sprintf($line, Fit::plain($text->text()));
        }

        return $lines;
    }
}
