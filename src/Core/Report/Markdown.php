<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;

use DateTimeImmutable;

use function implode;
use function max;

use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScores;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The verdict as GitHub Markdown, for the sticky comment and the step
 * summary: the verdict, each tree's floor and score with its change against
 * the base, the new-code sets and each package's security set, the score of
 * each owner group the change touched, the survivors, the unjudged and flaky
 * mutants, the ignored mutants with why, why the run could not judge, the
 * failures, the warnings, the floors that can rise, and a link to the run.
 * The comment holds up to 20 security survivors, in a section before the
 * trees (ADR-0021), after the trees the files the change reaches that fell
 * most or sit below their floor (ADR-0100), up to 20 other survivors on changed lines, each with its
 * diff, hint and reproduce command, up to 20 other unjudged and flaky
 * mutants, and up to 20 ignored mutants; the summary lists every mutant
 * counted as not killed and every ignored one in a table each (ADR-0009;
 * ADR-0008). A cluster of survivors is one item of either, with its members'
 * diffs, one hint and its stub command. Where the project has a CODEOWNERS,
 * the survivors on changed lines and those not killed are under the owner
 * group of their files, and under `owners.mention: first` the comment's
 * headings hold the placeholders {@see OwnerMentions} resolves as the
 * comment is written (ADR-0022).
 *
 * @internal
 */
final readonly class Markdown
{
    /** The hidden marker the sticky comment is found by. */
    public const string MARKER = '<!-- mutation-gate -->';

    /** Where a comment holds the line that links its run's HTML report, until the comment is written. */
    public const string REPORT_SLOT = '<!-- mutation-gate: html report -->';

    /** The most bytes one step's summary may hold. */
    public const int SUMMARY_BYTES = 1_048_576;

    /** The most characters GitHub takes in a comment's body. */
    public const int COMMENT_CHARACTERS = 65_536;

    private const string RUN = '[The run](%s) keeps the HTML report among its artifacts.';

    /**
     * The sticky comment, as the config shapes it (ADR-0102): within what GitHub takes, showing half as many of each
     * list's entries each time until it fits, none at the last. Where it shows the report, it ends where the line
     * that links the run's HTML report goes, which is found as the comment is written.
     */
    public static function comment(Verdict $verdict, CommentShows $shows): string
    {
        return CommentDocument::of($verdict, $shows);
    }

    /** The line that links the run at this URL, which keeps the HTML report among its artifacts. */
    public static function runLine(string $run): string
    {
        return sprintf(self::RUN, $run);
    }

    /**
     * The summary, with what the default branch saved in the window ending now under the headline: within the
     * bytes one step may write, showing half as many survivors each time until it fits, none at the last.
     */
    public static function summary(Verdict $verdict, string $run, SavingsWindow $window, DateTimeImmutable $now): string
    {
        $overview = Overview::of($verdict);
        $items = Folded::of($overview->survivors(), $verdict->trees()->clusters());
        $head = MarkdownBlocks::head(
            $verdict,
            $overview,
            $window->saved($verdict->account()->savedSince($window->since($now))),
        );
        $sets = [
            ...MarkdownBlocks::trees($verdict),
            ...MarkdownBlocks::sets($verdict),
            ...MarkdownBlocks::section('Suites', self::suites($verdict)),
        ];
        $tail = [
            ...MarkdownBlocks::section('Cannot judge', MarkdownBlocks::obstacles($verdict)),
            ...MarkdownBlocks::section('Failures', MarkdownBlocks::failures($verdict)),
            ...MarkdownBlocks::section('Warnings', MarkdownBlocks::warnings($verdict)),
            ...MarkdownBlocks::section('Notes', MarkdownBlocks::notes($verdict)),
            ...MarkdownBlocks::section('Floors that can rise', MarkdownBlocks::floors($verdict)),
            $run === '' ? '' : self::runLine($run),
        ];
        $shown = ItemShown::standard();
        $document = static fn(int $count): string => MarkdownBlocks::document([
            ...$head,
            ...$sets,
            ...MarkdownBlocks::section(
                sprintf('Not killed (%d)', count($overview->survivors())),
                MarkdownItems::owned(
                    $verdict->sets()->owners(),
                    $overview->survivors(),
                    $items,
                    $count,
                    static fn(array $items, int $of): array => MarkdownItems::table(
                        $items,
                        $of,
                        $overview->gate(),
                        $shown,
                    ),
                    MarkdownItems::named(...),
                ),
            ),
            ...MarkdownBlocks::section(
                sprintf('Ignored (%d)', count($overview->ignored())),
                MarkdownBlocks::ignored($overview, $count),
            ),
            ...$tail,
        ]);

        return MarkdownBlocks::fitted(
            $document,
            max(count($items), count($overview->ignored())),
            static fn(string $summary): bool => Bytes::length($summary) <= self::SUMMARY_BYTES,
        );
    }

    /** Whether a mutant is neither unjudged nor flaky, which the comment lists apart from the survivors. */
    public static function isSettled(JudgedMutant $mutant): bool
    {
        return $mutant->judgement() !== MutantJudgement::Unjudged && $mutant->judgement() !== MutantJudgement::Flaky;
    }

    /**
     * What each suite alone kills, as a table, then why its scores are lower
     * bounds where they are; that none can be scored; or nothing, where the
     * config declares fewer than two suites (ADR-0025).
     *
     * @return list<string>
     */
    private static function suites(Verdict $verdict): array
    {
        $scores = SuiteScores::of($verdict);

        if (! SuiteText::shows($scores) || ! $scores->arePlaced()) {
            return SuiteText::lines(
                $scores,
                $verdict->matrix()->whyNotFull(),
                $verdict->account()->provenance()->command(),
            );
        }

        $rows = ['| Suite | Covered | Killed | Score |', '|---|---|---|---|'];
        $exact = true;

        foreach ($scores as $score) {
            $value = $score->score();
            $rows[] = sprintf(
                '| %s | %d | %d | %s%s |',
                Escape::code($score->suite()),
                $score->covered(),
                $score->killed(),
                $value instanceof Score && ! $score->isExact() ? 'at least ' : '',
                Percent::of($value),
            );
            $exact = $exact && $score->isExact();
        }

        return [
            implode("\n", $rows),
            ...$exact ? [] : [Escape::text(SuiteText::lowerBound(
                $verdict->matrix()->whyNotFull(),
                $verdict->account()->provenance()->command(),
            ))],
        ];
    }
}
