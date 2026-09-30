<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_slice;
use function count;
use function implode;
use function intdiv;
use function iterator_to_array;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Report\Mutator;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function rtrim;
use function sprintf;

/**
 * The verdict as GitHub Markdown, for the sticky comment and the step
 * summary: the verdict, each tree's floor and score with its change against
 * the base, the new-code sets, the survivors, the unjudged and flaky
 * mutants, the failures, the warnings, the floors that can rise, and a link
 * to the run. The comment holds up to 20 survivors on changed lines, each
 * with its diff, hint and reproduce command; the summary lists every mutant
 * counted as not killed in a table (ADR-0009, decision 3).
 */
final readonly class Markdown
{
    /** The hidden marker the sticky comment is found by. */
    public const string MARKER = '<!-- mutation-gate -->';

    /** The most bytes one step's summary may hold. */
    public const int SUMMARY_BYTES = 1_048_576;

    /** The most survivors, and unjudged or flaky mutants, the comment lists. */
    private const int COMMENTED = 20;

    private const string BYTES = '8bit';

    private const string RAISE
        = 'Raise them with `vendor/bin/mutation-gate baseline --write`, and commit the baseline.';

    private const string MORE = 'And %d more; the JSON report lists every one.';

    private const string RUN = '[The run](%s) keeps the HTML report among its artifacts.';

    public static function comment(Verdict $verdict, string $run): string
    {
        $overview = Overview::of($verdict);
        $changed = [];
        $other = [];

        foreach ($overview->survivors() as $mutant) {
            if (self::isUnsettled($mutant)) {
                $other[] = $mutant;

                continue;
            }

            $changed = $mutant->isOnChangedLine() ? [...$changed, $mutant] : $changed;
        }

        return self::document([
            self::MARKER,
            ...self::head($verdict, $overview),
            ...self::section(
                sprintf('Survivors on changed lines (%d)', count($changed)),
                self::details(array_slice($changed, 0, self::COMMENTED), count($changed)),
            ),
            ...self::section(
                sprintf('Unjudged and flaky (%d)', count($other)),
                self::table(array_slice($other, 0, self::COMMENTED), count($other)),
            ),
            ...self::tail($verdict, $run),
        ]);
    }

    public static function summary(Verdict $verdict, string $run): string
    {
        $overview = Overview::of($verdict);
        $survivors = iterator_to_array($overview->survivors(), preserve_keys: false);
        $head = self::head($verdict, $overview);
        $tail = self::tail($verdict, $run);
        $shown = count($survivors);

        do {
            $summary = self::document([
                ...$head,
                ...self::section(
                    sprintf('Not killed (%d)', count($survivors)),
                    self::table(array_slice($survivors, 0, $shown), count($survivors)),
                ),
                ...$tail,
            ]);
            $shown = intdiv($shown, 2);
        } while (mb_strlen($summary, self::BYTES) > self::SUMMARY_BYTES && $shown > 0);

        return $summary;
    }

    /**
     * @param  list<string> $blocks
     */
    private static function document(array $blocks): string
    {
        return sprintf("%s\n", implode("\n\n", $blocks));
    }

    /** @return list<string> */
    private static function head(Verdict $verdict, Overview $overview): array
    {
        $trees = ['| Tree | Floor | Score | Against the base | Result |', '|---|---|---|---|---|'];

        foreach ($verdict->trees() as $tree) {
            $trees[] = self::treeRow($tree);
        }

        $sets = [];

        foreach ($verdict->newCode() as $set) {
            $sets[] = sprintf('- %s', Escape::text(SetText::newCode($set)));
        }

        return [
            sprintf('## mutation-gate: %s', $verdict->judgement()->value),
            implode(' ', [
                SetText::project($overview->score()),
                ...$verdict->wasCutShort() ? ['The run\'s budget stopped it before every mutant was judged.'] : [],
            ]),
            ...count($verdict->trees()) > 0 ? [implode("\n", $trees)] : [],
            ...$sets === [] ? [] : [implode("\n", $sets)],
        ];
    }

    private static function treeRow(TreeVerdict $tree): string
    {
        $floor = $tree->floor();
        $score = $tree->score();
        $base = $tree->base();

        return sprintf(
            '| %s | %s | %s | %s | %s |',
            Escape::code($tree->tree()->path()->value()),
            SetText::floor($floor),
            $floor instanceof Exempt ? '' : Percent::of($score),
            $score instanceof Score && $base instanceof Score ? Percent::change($base, $score) : '',
            Escape::text(
                $floor instanceof Exempt ? sprintf('exempt: %s', $floor->reason()) : $tree->judgement()->value,
            ),
        );
    }

    /** @return list<string> */
    private static function tail(Verdict $verdict, string $run): array
    {
        $raised = [];

        foreach ($verdict->trees() as $tree) {
            $floor = $tree->raised();
            $raised = $floor instanceof Floor
                ? [...$raised, sprintf('- %s to %s', Escape::code($tree->tree()->path()->value()), Percent::of($floor))]
                : $raised;
        }

        return [
            ...self::section('Failures', self::bullets($verdict->failures())),
            ...self::section('Warnings', self::bullets($verdict->warnings())),
            ...self::section('Floors that can rise', $raised === [] ? [] : [implode("\n", $raised), self::RAISE]),
            ...$run === '' ? [] : [sprintf(self::RUN, $run)],
        ];
    }

    /**
     * A heading and its blocks; nothing where there are none.
     *
     * @param  list<string> $blocks
     * @return list<string>
     */
    private static function section(string $heading, array $blocks): array
    {
        return $blocks === [] ? [] : [sprintf('### %s', $heading), ...$blocks];
    }

    /**
     * Each mutant as a folded block with its diff, hint and reproduce command.
     *
     * @param  list<JudgedMutant> $mutants
     * @return list<string>
     */
    private static function details(array $mutants, int $of): array
    {
        $blocks = [];

        foreach ($mutants as $judged) {
            $mutant = $judged->mutant();
            $blocks[] = implode("\n\n", [
                sprintf(
                    '<details><summary>%s %s, %s</summary>',
                    self::place($judged),
                    Escape::text(Mutator::short($mutant->mutation()->mutator())),
                    Label::of($judged->judgement()),
                ),
                Escape::block(rtrim($mutant->mutation()->diff(), "\n"), 'diff'),
                Escape::text($judged->hint()->text()),
                Escape::code($judged->reproduce()),
                '</details>',
            ]);
        }

        return [...$blocks, ...self::more($of - count($mutants))];
    }

    /**
     * Mutants as a table: where, mutator, judgement, why and how to reproduce.
     *
     * @param  list<JudgedMutant> $mutants
     * @return list<string>
     */
    private static function table(array $mutants, int $of): array
    {
        if ($mutants === []) {
            return [];
        }

        $rows = ['| Mutant | Mutator | Judgement | What the tests miss | Reproduce |', '|---|---|---|---|---|'];

        foreach ($mutants as $judged) {
            $mutant = $judged->mutant();
            $reason = $mutant->reason();
            $rows[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                self::place($judged),
                Escape::text(Mutator::short($mutant->mutation()->mutator())),
                Label::of($judged->judgement()),
                Escape::text($reason instanceof Reason
                    ? sprintf('%s %s', $reason->text(), $judged->hint()->text())
                    : $judged->hint()->text()),
                Escape::code($judged->reproduce()),
            );
        }

        return [implode("\n", $rows), ...self::more($of - count($mutants))];
    }

    /** @return list<string> */
    private static function more(int $left): array
    {
        return $left > 0 ? [sprintf(self::MORE, $left)] : [];
    }

    /** @return list<string> */
    private static function bullets(Failures|Warnings $items): array
    {
        $bullets = [];

        foreach ($items as $item) {
            $bullets[] = sprintf('- %s', Escape::text($item->text()));
        }

        return $bullets === [] ? [] : [implode("\n", $bullets)];
    }

    /** Where a mutant is, as code: its file and line. */
    private static function place(JudgedMutant $judged): string
    {
        $location = $judged->mutant()->location();

        return Escape::code(sprintf('%s:%d', $location->file()->value(), $location->start()->number()));
    }

    /** Whether a mutant is unjudged or flaky, which the comment lists apart from the survivors. */
    private static function isUnsettled(JudgedMutant $mutant): bool
    {
        return $mutant->judgement() === MutantJudgement::Unjudged || $mutant->judgement() === MutantJudgement::Flaky;
    }
}
