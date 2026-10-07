<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;
use function array_map;
use function array_slice;

use Closure;

use function count;
use function implode;
use function intdiv;
use function iterator_to_array;
use function max;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScores;
use NightWorksIO\MutationGate\Core\Report\Commented;
use NightWorksIO\MutationGate\Core\Report\CostText;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Report\Folded;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\RisingFloors;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Report\SuiteText;
use NightWorksIO\MutationGate\Core\Score\Exempt;
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
 * the base, the new-code sets and each package's security set, the
 * survivors, the unjudged and flaky mutants, the ignored mutants with why,
 * why the run could not judge, the failures, the warnings, the floors that
 * can rise, and a link to the run. The comment holds up to 20 security
 * survivors, in a section before the trees (ADR-0021, decision 21), up to 20
 * other survivors on changed lines, each with its diff, hint and reproduce
 * command, up to 20 other unjudged and flaky mutants, and up to 20 ignored
 * mutants; the summary lists
 * every mutant counted as not killed and every ignored one in a table each
 * (ADR-0009, decision 3; ADR-0008, decision 4). A cluster of survivors is one item of either, with
 * its members' diffs, one hint and its stub command (ADR-0022, decision 17).
 */
final readonly class Markdown
{
    /** The hidden marker the sticky comment is found by. */
    public const string MARKER = '<!-- mutation-gate -->';

    /** The most bytes one step's summary may hold. */
    public const int SUMMARY_BYTES = 1_048_576;

    /** The most characters GitHub takes in a comment's body. */
    public const int COMMENT_CHARACTERS = 65_536;

    private const string RAISE
        = 'Raise them with `vendor/bin/mutation-gate baseline --write`, and commit the baseline.';

    private const string RUN = '[The run](%s) keeps the HTML report among its artifacts.';

    /**
     * The sticky comment: within what GitHub takes, showing half as many of each list's entries each time until it
     * fits, none at the last.
     */
    public static function comment(Verdict $verdict, string $run): string
    {
        $overview = Overview::of($verdict);
        $clusters = $verdict->trees()->clusters();
        $changed = [];
        $other = [];

        foreach ($overview->survivors() as $mutant) {
            if ($overview->isSecurity($mutant)) {
                continue;
            }

            if (self::isUnsettled($mutant)) {
                $other[] = $mutant;

                continue;
            }

            $changed = $mutant->isOnChangedLine() ? [...$changed, $mutant] : $changed;
        }

        $folded = Folded::of($changed, $clusters);
        $security = $overview->securitySurvivors();
        $secured = Folded::of($security, $clusters);
        $document = static fn(int $shown): string => self::document([
            self::MARKER,
            ...self::head($verdict, $overview, NoHistory::yet()),
            ...self::section(
                sprintf('Security survivors (%d)', count($security)),
                MarkdownItems::details(array_slice($secured, 0, $shown), count($secured)),
            ),
            ...self::sets($verdict),
            ...self::section(
                sprintf('Survivors on changed lines (%d)', count($changed)),
                MarkdownItems::details(array_slice($folded, 0, $shown), count($folded)),
            ),
            ...self::section(
                sprintf('Unjudged and flaky (%d)', count($other)),
                MarkdownItems::table(array_slice($other, 0, $shown), count($other)),
            ),
            ...self::ignored($overview, $shown),
            ...self::tail($verdict, $run),
            CostText::of($verdict),
        ]);

        return self::fitted(
            $document,
            Commented::MOST,
            static fn(string $comment): bool => mb_strlen($comment) <= self::COMMENT_CHARACTERS,
        );
    }

    /**
     * The summary, with what the default branch saved since this instant under the headline: within the bytes one
     * step may write, showing half as many survivors each time until it fits, none at the last.
     */
    public static function summary(Verdict $verdict, string $run, Instant $since): string
    {
        $overview = Overview::of($verdict);
        $items = Folded::of($overview->survivors(), $verdict->trees()->clusters());
        $head = self::head($verdict, $overview, $verdict->account()->savedSince($since));
        $tail = self::tail($verdict, $run);
        $document = static fn(int $shown): string => self::document([
            ...$head,
            ...self::sets($verdict),
            ...self::section('Suites', self::suites($verdict)),
            ...self::section(
                sprintf('Not killed (%d)', count($overview->survivors())),
                MarkdownItems::table(array_slice($items, 0, $shown), count($items)),
            ),
            ...self::ignored($overview, $shown),
            ...$tail,
        ]);

        return self::fitted(
            $document,
            max(count($items), count($overview->ignored())),
            static fn(string $summary): bool => Bytes::length($summary) <= self::SUMMARY_BYTES,
        );
    }

    /**
     * A document showing this many entries of each list, or half as many each time until it fits, none at the last.
     *
     * @param Closure(int): string  $document the document showing this many entries of each list
     * @param Closure(string): bool $fits     whether a document fits
     */
    private static function fitted(Closure $document, int $shown, Closure $fits): string
    {
        do {
            $fitted = $document($shown);
            $tried = $shown;
            $shown = intdiv($shown, 2);
        } while (! $fits($fitted) && $tried > 0);

        return $fitted;
    }

    /**
     * @param  list<string> $blocks
     */
    private static function document(array $blocks): string
    {
        return sprintf("%s\n", implode("\n\n", array_filter($blocks, static fn(string $block): bool => $block !== '')));
    }

    /**
     * The verdict, what the run took and saved, and the project's score.
     *
     * @return list<string>
     */
    private static function head(Verdict $verdict, Overview $overview, Seconds|NoHistory $lately): array
    {
        return [
            sprintf('## mutation-gate: %s', $verdict->judgement()->value),
            SavingsText::of($verdict, $lately),
            implode(' ', [
                SetText::project($overview->score()),
                ...$verdict->wasCutShort() ? ['The run stopped before it judged every mutant.'] : [],
            ]),
        ];
    }

    /**
     * The trees, then the new-code sets and each package's security set.
     *
     * @return list<string>
     */
    private static function sets(Verdict $verdict): array
    {
        $trees = ['| Tree | Floor | Score | Against the base | Result |', '|---|---|---|---|---|'];

        foreach ($verdict->trees() as $tree) {
            $trees[] = self::treeRow($tree);
        }

        $sets = [];

        foreach ($verdict->sets()->newCode() as $set) {
            $sets[] = sprintf('- %s', Escape::text(SetText::newCode($set)));
        }

        foreach ($verdict->sets()->security() as $set) {
            $sets[] = sprintf('- %s', Escape::text(SetText::security($set)));
        }

        return [
            ...count($verdict->trees()) > 0 ? [implode("\n", $trees)] : [],
            ...$sets === [] ? [] : [implode("\n", $sets)],
        ];
    }

    /**
     * What each suite alone kills, as a table, then why its scores are lower
     * bounds where they are; that none can be scored; or nothing, where the
     * config declares fewer than two suites (ADR-0025, decision 8).
     *
     * @return list<string>
     */
    private static function suites(Verdict $verdict): array
    {
        $scores = SuiteScores::of($verdict);

        if (! SuiteText::shows($scores) || ! $scores->arePlaced()) {
            return SuiteText::lines($scores, $verdict->matrix()->whyNotFull());
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
            ...$exact ? [] : [Escape::text(SuiteText::lowerBound($verdict->matrix()->whyNotFull()))],
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

    /**
     * The mutants an ignore left out, up to this many, each with why (ADR-0008, decision 4).
     *
     * @return list<string>
     */
    private static function ignored(Overview $overview, int $shown): array
    {
        $ignored = $overview->ignored();

        return self::section(
            sprintf('Ignored (%d)', count($ignored)),
            MarkdownItems::ignored(array_slice($ignored, 0, $shown), count($ignored)),
        );
    }

    /** @return list<string> */
    private static function tail(Verdict $verdict, string $run): array
    {
        $raised = [];

        foreach (RisingFloors::of($verdict) as [$named, $floor]) {
            $raised[] = sprintf('- %s to %s', Escape::code($named), Percent::of($floor));
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
