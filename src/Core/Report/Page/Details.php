<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_key_exists;
use function count;
use function implode;
use function is_array;

use NightWorksIO\MutationGate\Core\Report\AccountJson;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\SuiteText;

use function sprintf;

/**
 * The HTML report's run details, folded away: what the run warns of, what
 * failed it whatever the scores, what kept it from judging, why the change
 * reached what it did, what each suite alone kills, and how long each part
 * of the run took. A part with nothing in it is left out.
 *
 * @phpstan-import-type Report from JsonReport
 * @phpstan-import-type Run from AccountJson
 */
final readonly class Details
{
    private const string FOLD = <<<'HTML'
        <details class="card details" id="details">
        <summary><h2>Run details</h2></summary>
        %s
        </details>
        HTML;

    private const string PART = '<section><h3>%s</h3><ul>%s</ul></section>';

    private const string NOTHING = '<p class="empty">The run left nothing more to say.</p>';

    private const string WALL = 'The run took %s of wall time and %s of runner time%s.';

    private const string ESTIMATED = ', estimated';

    private const string SHARD = 'Shard %d took %s.';

    private const string PHASE = 'The %s took %s, from %s.';


    /** @param Report $report */
    public static function html(array $report): string
    {
        $parts = [
            self::part('Failures', self::texts($report['failures'])),
            self::part('Cannot judge', self::texts($report['cannotJudge'])),
            self::part('Warnings', self::texts($report['warnings'])),
            self::part('Reach', self::texts($report['reach'])),
            self::part('Suites', self::suites($report)),
            self::part('Timings', array_key_exists('run', $report) ? self::timings($report['run']) : []),
        ];
        $shown = implode('', $parts);

        return sprintf(self::FOLD, $shown === '' ? self::NOTHING : $shown);
    }

    /** @param list<string> $items each already HTML */
    private static function part(string $title, array $items): string
    {
        return $items === [] ? '' : sprintf(self::PART, $title, Html::items($items));
    }

    /**
     * @param  list<string> $texts
     * @return list<string>
     */
    private static function texts(array $texts): array
    {
        $escaped = [];

        foreach ($texts as $text) {
            $escaped[] = Html::text($text);
        }

        return $escaped;
    }

    /**
     * What each suite alone kills, where the config declares two or more;
     * or why none can be scored.
     *
     * @param  Report       $report
     * @return list<string>
     */
    private static function suites(array $report): array
    {
        $said = [];

        foreach (count($report['suites']) >= SuiteText::SHOWN_FROM ? $report['suites'] : [] as $suite) {
            $said[] = Html::text($suite['said']);
        }

        return match (true) {
            array_key_exists('suitesUnscored', $report) => [Html::text($report['suitesUnscored'])],
            array_key_exists('suitesLowerBound', $report) && $said !== []
                => [...$said, Html::text($report['suitesLowerBound'])],
            default => $said,
        };
    }

    /**
     * @param  Run          $run
     * @return list<string>
     */
    private static function timings(array $run): array
    {
        $said = [sprintf(
            self::WALL,
            Html::seconds($run['wallSeconds']),
            Html::seconds($run['runnerSeconds']),
            $run['measured'] ? '' : self::ESTIMATED,
        )];

        foreach (is_array($run['phases']) ? $run['phases'] : [] as $name => $phase) {
            $said[] = Html::text(sprintf(self::PHASE, $name, Html::seconds($phase['seconds']), $phase['start']));
        }

        foreach ($run['shards'] as $shard) {
            $said[] = sprintf(self::SHARD, $shard['shard'], Html::seconds($shard['seconds']));
        }

        return $said;
    }
}
