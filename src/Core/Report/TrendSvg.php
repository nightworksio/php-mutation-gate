<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_keys;
use function array_map;
use function count;
use function implode;
use function iterator_to_array;
use function max;

use NightWorksIO\MutationGate\Core\Score\Percentage;

use function sprintf;

/**
 * `trend.svg`: the project's score over the runs a trend holds, as a plain
 * sparkline on a scale of 0 to 100, with no script, for the step summary and
 * the HTML report to show.
 */
final readonly class TrendSvg
{
    private const int WIDTH = 240;

    private const int HEIGHT = 40;

    private const string STROKE = '#4c1';

    private const string SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img">
        <title>%3$s</title>
        <polyline fill="none" stroke="%4$s" stroke-width="2" points="%5$s"/>
        </svg>

        SVG;

    public static function of(Trend $trend): string
    {
        $scores = iterator_to_array($trend->scores(), preserve_keys: false);
        $last = count($scores) - 1;
        $step = self::WIDTH / max($last, 1);
        $points = array_map(
            static fn(int $at, float $score): string => sprintf(
                '%.1f,%.1f',
                $at * $step,
                self::HEIGHT - (Percentage::fractionOf($score) * self::HEIGHT),
            ),
            array_keys($scores),
            $scores,
        );
        $title = $last < 0
            ? 'Mutation score: no runs yet'
            : sprintf(
                'Mutation score over %d %s, %.2f%% at the last',
                count($scores),
                $last === 0 ? 'run' : 'runs',
                $scores[$last],
            );

        return sprintf(self::SVG, self::WIDTH, self::HEIGHT, $title, self::STROKE, implode(' ', $points));
    }
}
