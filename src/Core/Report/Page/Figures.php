<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function array_sum;
use function implode;

use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

use function sprintf;

/**
 * The HTML report's figures: how many mutants came to each outcome, how
 * many the score leaves out, and each set's score against its floor as a
 * bar, the floor marked on it.
 *
 * @phpstan-import-type Report from JsonReport
 * @phpstan-import-type SecurityEntry from JsonReport
 */
final readonly class Figures
{
    private const string CARD = <<<'HTML'
        <section class="card figures" aria-labelledby="figures-title">
        <h2 id="figures-title">Mutants</h2>
        <ul class="tiles">%s</ul>
        <p class="aside">%s</p>
        <ul class="sets">%s</ul>
        </section>
        HTML;

    private const string TILE
        = '<li class="tile tile--%s"><span class="tile-count">%d</span><span class="tile-label">%s</span></li>';

    private const string JUDGED = '%d judged, %d left out of the score.';

    private const string SET = <<<'HTML'
        <li class="set set--%1$s"><p class="set-said">%2$s</p>%3$s</li>
        HTML;

    private const string BAR = <<<'HTML'
        <div class="bar" role="img" aria-label="%1$s">
        <span class="bar-fill" style="width: %2$.2F%%"></span>%3$s
        </div>
        HTML;

    private const string FLOOR = '<span class="bar-floor" style="left: %.2F%%" title="floor %s"></span>';

    private const string SCORED = 'Score %s';

    private const string AGAINST = 'Score %s against a floor of %s';

    /** @param Report $report */
    public static function html(array $report): string
    {
        $counts = self::counts($report['counts']);
        $tiles = [];

        foreach (Outcome::counted() as $outcome) {
            $tiles[] = sprintf(self::TILE, $outcome->value, $counts[$outcome->value], $outcome->label());
        }

        $sets = [];

        foreach ([...$report['trees'], ...$report['newCode'], ...$report['security']] as $set) {
            $sets[] = self::set($set);
        }

        return sprintf(
            self::CARD,
            implode('', $tiles),
            sprintf(self::JUDGED, array_sum($counts), $counts[Outcome::LeftOut->value]),
            implode('', $sets),
        );
    }

    /**
     * How many mutants came to each outcome, by its name.
     *
     * @param  array<string, int> $judged how many came to each judgement, by its name
     * @return array<string, int>
     */
    private static function counts(array $judged): array
    {
        $counts = [];

        foreach (Outcome::cases() as $outcome) {
            $counts[$outcome->value] = 0;
        }

        foreach ($judged as $judgement => $number) {
            $counts[Outcome::of(MutantJudgement::from($judgement))->value] += $number;
        }

        return $counts;
    }

    /** @param SecurityEntry $set a security set, or a tree or a new-code set, which hold what it holds */
    private static function set(array $set): string
    {
        $bar = match (true) {
            ! array_key_exists('score', $set) => '',
            array_key_exists('floor', $set) => self::bar(
                $set['score'],
                sprintf(self::AGAINST, Html::percent($set['score']), Html::percent($set['floor'])),
                sprintf(self::FLOOR, $set['floor'], Html::percent($set['floor'])),
            ),
            default => self::bar($set['score'], sprintf(self::SCORED, Html::percent($set['score'])), ''),
        };

        return sprintf(self::SET, Html::text($set['judgement']), Html::text($set['said']), $bar);
    }

    /** A bar filled to a score, said for a reader that sees no bar, with its floor marked where it has one. */
    private static function bar(float $score, string $said, string $floor): string
    {
        return sprintf(self::BAR, Html::text($said), $score, $floor);
    }
}
