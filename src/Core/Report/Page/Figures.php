<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function array_keys;
use function array_sum;
use function implode;

use NightWorksIO\MutationGate\Core\Report\Grade;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Outcome;
use NightWorksIO\MutationGate\Core\Report\Term;

use function sprintf;

/**
 * The HTML report's figures: how many mutants came to each outcome, each
 * graded in a word with why, how many the score leaves out, and each set's
 * score against its floor as a bar, the floor marked on it. Each word a
 * reader new to mutation testing may not know has its "what is this?".
 *
 * @phpstan-import-type Report from JsonReport
 * @phpstan-import-type SecurityEntry from JsonReport
 */
final readonly class Figures
{
    private const string CARD = <<<'HTML'
        <section class="card figures" aria-labelledby="figures-title">
        <div class="card-head"><h2 id="figures-title">Mutants</h2>%s</div>
        <ul class="tiles">%s</ul>
        <div class="left-out">%s</div>
        <ul class="sets">%s</ul>
        <div class="terms">%s</div>
        </section>
        HTML;

    private const string TILE = <<<'HTML'
        <li class="tile tile--%1$s"><span class="tile-count">%2$d</span><span class="tile-label">%3$s</span>
        %4$s<p class="tile-why">%5$s</p>%6$s</li>
        HTML;

    private const string GRADE = '<span class="grade grade--%s">%s</span>';

    private const string JUDGED = '<p class="aside">%d judged, %d left out of the score. %s %s</p>%s';

    private const string TERM = '<span class="term-word">%s</span>%s';

    private const string SET = <<<'HTML'
        <li class="set set--%1$s"><p class="set-said">%2$s</p>%3$s</li>
        HTML;

    private const string BAR = <<<'HTML'
        <div class="bar" role="img" aria-label="%1$s">
        <span class="bar-fill" style="%2$s"></span>%3$s
        </div>
        HTML;

    private const string FLOOR = '<span class="bar-floor" style="%s" title="floor %s"></span>';

    /** How far a bar is filled: its score. */
    private const string FILLED = 'width: %.2F%%';

    /** Where a bar's floor is marked: at the floor. */
    private const string MARKED = 'left: %.2F%%';

    private const string SCORED = 'Score %s';

    private const string AGAINST = 'Score %s against a floor of %s';

    /** @param Report $report */
    public static function html(array $report, Glossary $glossary): string
    {
        $counts = Outcome::counts($report['counts']);
        $tiles = [];

        foreach (Outcome::counted() as $outcome) {
            $tiles[] = sprintf(
                self::TILE,
                $outcome->value,
                $counts[$outcome->value],
                $outcome->label(),
                self::grade($outcome->grade()),
                Html::text($outcome->why()),
                $glossary->explain($outcome->term()),
            );
        }

        $sets = [];

        foreach (self::sets($report) as $set) {
            $sets[] = self::set($set);
        }

        $leftOut = Outcome::LeftOut;

        return sprintf(
            self::CARD,
            $glossary->explain(Term::Mutant),
            implode('', $tiles),
            sprintf(
                self::JUDGED,
                array_sum($counts),
                $counts[$leftOut->value],
                self::grade($leftOut->grade()),
                Html::text($leftOut->why()),
                $glossary->explain($leftOut->term()),
            ),
            implode('', $sets),
            implode('', [
                sprintf(self::TERM, 'Score', $glossary->explain(Term::Score)),
                sprintf(self::TERM, 'Floor', $glossary->explain(Term::Floor)),
            ]),
        );
    }

    /**
     * Every style attribute the figures write, each once: each bar's fill and
     * each floor's mark, which the page's Content Security Policy names.
     *
     * @param Report $report
     *
     * @return list<string>
     */
    public static function styles(array $report): array
    {
        $styles = [];

        foreach (self::sets($report) as $set) {
            $styles = match (true) {
                ! array_key_exists('score', $set) => $styles,
                array_key_exists('floor', $set) => [
                    ...$styles,
                    sprintf(self::FILLED, $set['score']) => true,
                    sprintf(self::MARKED, $set['floor']) => true,
                ],
                default => [...$styles, sprintf(self::FILLED, $set['score']) => true],
            };
        }

        return array_keys($styles);
    }

    /** An outcome's grade, said in a word so that no reader needs the colour to tell. */
    public static function grade(Grade $grade): string
    {
        return sprintf(self::GRADE, $grade->value, $grade->label());
    }

    /** @param SecurityEntry $set a security set, or a tree or a new-code set, which hold what it holds */
    private static function set(array $set): string
    {
        $bar = match (true) {
            ! array_key_exists('score', $set) => '',
            array_key_exists('floor', $set) => self::bar(
                $set['score'],
                sprintf(self::AGAINST, Html::percent($set['score']), Html::percent($set['floor'])),
                sprintf(self::FLOOR, sprintf(self::MARKED, $set['floor']), Html::percent($set['floor'])),
            ),
            default => self::bar($set['score'], sprintf(self::SCORED, Html::percent($set['score'])), ''),
        };

        return sprintf(self::SET, Html::text($set['judgement']), Html::text($set['said']), $bar);
    }

    /** A bar filled to a score, said for a reader that sees no bar, with its floor marked where it has one. */
    private static function bar(float $score, string $said, string $floor): string
    {
        return sprintf(self::BAR, Html::text($said), sprintf(self::FILLED, $score), $floor);
    }

    /**
     * Every set the figures score: each tree, the new code and each security set.
     *
     * @param Report $report
     *
     * @return list<SecurityEntry>
     */
    private static function sets(array $report): array
    {
        return [...$report['trees'], ...$report['newCode'], ...$report['security']];
    }
}
