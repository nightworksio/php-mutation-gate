<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;
use function array_map;
use function array_slice;
use function count;
use function implode;
use function intdiv;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Report\CostText;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Report\Folded;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Obstacles;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * The verdict as GitHub Markdown, for the sticky comment and the step
 * summary: the verdict, each tree's floor and score with its change against
 * the base, the new-code sets, the survivors, the unjudged and flaky
 * mutants, why the run could not judge, the failures, the warnings, the
 * floors that can rise, and a link to the run. The comment holds up to 20
 * survivors on changed lines, each with its diff, hint and reproduce
 * command; the summary lists every mutant counted as not killed in a table
 * (ADR-0009, decision 3). A cluster of survivors is one item of either, with
 * its members' diffs, one hint and its stub command (ADR-0022, decision 17).
 */
final readonly class Markdown
{
    /** The hidden marker the sticky comment is found by. */
    public const string MARKER = '<!-- mutation-gate -->';

    /** The most bytes one step's summary may hold. */
    public const int SUMMARY_BYTES = 1_048_576;

    /** The most entries a list of the comment holds, before it says how many more there are. */
    public const int COMMENTED = 20;

    private const string RAISE
        = 'Raise them with `vendor/bin/mutation-gate baseline --write`, and commit the baseline.';

    private const string RUN = '[The run](%s) keeps the HTML report among its artifacts.';

    public static function comment(Verdict $verdict, string $run): string
    {
        $overview = Overview::of($verdict);
        $clusters = $verdict->trees()->clusters();
        $changed = [];
        $other = [];

        foreach ($overview->survivors() as $mutant) {
            if (self::isUnsettled($mutant)) {
                $other[] = $mutant;

                continue;
            }

            $changed = $mutant->isOnChangedLine() ? [...$changed, $mutant] : $changed;
        }

        $shown = Folded::of($changed, $clusters);

        return self::document([
            self::MARKER,
            ...self::head($verdict, $overview, NoHistory::yet()),
            ...self::section(
                sprintf('Survivors on changed lines (%d)', count($changed)),
                MarkdownItems::details(array_slice($shown, 0, self::COMMENTED), count($shown)),
            ),
            ...self::section(
                sprintf('Unjudged and flaky (%d)', count($other)),
                MarkdownItems::table(array_slice($other, 0, self::COMMENTED), count($other)),
            ),
            ...self::tail($verdict, $run),
            CostText::of($verdict),
        ]);
    }

    /** The summary, with what the default branch saved since this instant under the headline. */
    public static function summary(Verdict $verdict, string $run, Instant $since): string
    {
        $overview = Overview::of($verdict);
        $items = Folded::of($overview->survivors(), $verdict->trees()->clusters());
        $head = self::head($verdict, $overview, $verdict->account()->savedSince($since));
        $tail = self::tail($verdict, $run);
        $shown = count($items);

        do {
            $summary = self::document([
                ...$head,
                ...self::section(
                    sprintf('Not killed (%d)', count($overview->survivors())),
                    MarkdownItems::table(array_slice($items, 0, $shown), count($items)),
                ),
                ...$tail,
            ]);
            $shown = intdiv($shown, 2);
        } while (Bytes::length($summary) > self::SUMMARY_BYTES && $shown > 0);

        return $summary;
    }

    /**
     * @param  list<string> $blocks
     */
    private static function document(array $blocks): string
    {
        return sprintf("%s\n", implode("\n\n", array_filter($blocks, static fn(string $block): bool => $block !== '')));
    }

    /**
     * The verdict, what the run took and saved, the project's score, the trees and the new-code sets.
     *
     * @return list<string>
     */
    private static function head(Verdict $verdict, Overview $overview, Seconds|NoHistory $lately): array
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
            SavingsText::of($verdict, $lately),
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
            ...self::section('Cannot judge', self::reasons($verdict->obstacles())),
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

    /** @return list<string> */
    private static function bullets(Failures|Warnings $items): array
    {
        $bullets = [];

        foreach ($items as $item) {
            $bullets[] = sprintf('- %s', Escape::text($item->text()));
        }

        return $bullets === [] ? [] : [implode("\n", $bullets)];
    }

    /**
     * Why the run could not judge, as one list; nothing where it judged.
     *
     * @return list<string>
     */
    private static function reasons(Obstacles $obstacles): array
    {
        $reasons = array_map(
            static fn(CannotJudge $obstacle): string => sprintf('- %s', Escape::text($obstacle->why())),
            iterator_to_array($obstacles, preserve_keys: false),
        );

        return array_filter([implode("\n", $reasons)], static fn(string $list): bool => $list !== '');
    }

    /** Whether a mutant is unjudged or flaky, which the comment lists apart from the survivors. */
    private static function isUnsettled(JudgedMutant $mutant): bool
    {
        return $mutant->judgement() === MutantJudgement::Unjudged || $mutant->judgement() === MutantJudgement::Flaky;
    }
}
