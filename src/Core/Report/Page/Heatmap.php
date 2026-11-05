<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Report\HeatmapJson;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;

use function sprintf;

/**
 * The HTML report's heatmap (ADR-0084): every file the run scored, lowest
 * first, with its score, its change against the base and how many of its
 * mutants are not killed, its functions folded under it with theirs. The
 * whole table is folded, so the overview stays short; a run that scored
 * no file shows none.
 *
 * @internal
 *
 * @phpstan-import-type Report from JsonReport
 * @phpstan-import-type FileEntry from HeatmapJson
 * @phpstan-import-type HeatEntry from HeatmapJson
 */
final readonly class Heatmap
{
    private const string CARD = <<<'HTML'
        <section class="card heatmap" aria-labelledby="heatmap-title">
        <h2 id="heatmap-title">Scores by file <span class="count">%d</span></h2>
        <p class="aside">Lowest first, against the base where it has a score.</p>
        <details><summary>Every file</summary>
        <table class="heat">
        <thead>
        <tr>
        <th scope="col">File</th>
        <th scope="col">Score</th>
        <th scope="col">Against the base</th>
        <th scope="col">Not killed</th>
        </tr>
        </thead>
        <tbody>%s</tbody>
        </table>
        </details>
        </section>
        HTML;

    private const string ROW = '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td></tr>';

    private const string FOLDED = '<details><summary>%s</summary><ul class="heat-functions">%s</ul></details>';

    private const string FUNCTION = '<li><code>%s</code> %s <span class="heat-change">%s</span>%s</li>';

    private const string NOT_KILLED = ' <span class="tag tag--escaped">%d not killed</span>';


    /** @param Report $report */
    public static function html(array $report): string
    {
        if (! array_key_exists('heatmap', $report)) {
            return '';
        }

        $rows = [];

        foreach ($report['heatmap'] as $file) {
            $rows[] = self::row($file);
        }

        return sprintf(self::CARD, count($rows), implode('', $rows));
    }

    /** @param FileEntry $file */
    private static function row(array $file): string
    {
        $path = sprintf(Html::CODE, Html::text($file['path']));
        $functions = [];

        foreach ($file['functions'] as $function) {
            $functions[] = sprintf(
                self::FUNCTION,
                Html::text($function['name']),
                self::score($function),
                self::change($function),
                $function['notKilled'] > 0 ? sprintf(self::NOT_KILLED, $function['notKilled']) : '',
            );
        }

        return sprintf(
            self::ROW,
            $functions === [] ? $path : sprintf(self::FOLDED, $path, implode('', $functions)),
            self::score($file),
            self::change($file),
            $file['notKilled'],
        );
    }

    /** @param FileEntry|HeatEntry $heat */
    private static function score(array $heat): string
    {
        return array_key_exists('score', $heat) ? Html::percent($heat['score']) : NothingToMutate::SAID;
    }

    /** @param FileEntry|HeatEntry $heat */
    private static function change(array $heat): string
    {
        return array_key_exists('score', $heat) && array_key_exists('base', $heat)
            ? Percent::change(self::scoreOf($heat['base']), self::scoreOf($heat['score']))
            : '';
    }

    private static function scoreOf(float $percent): Score
    {
        return Score::ofHundredths(Percentage::hundredthsOf($percent));
    }
}
