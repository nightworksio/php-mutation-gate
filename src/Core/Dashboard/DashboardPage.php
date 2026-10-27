<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Http\Origin;
use NightWorksIO\MutationGate\Core\Report\Page\Html;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\TrendSvg;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function str_starts_with;

/**
 * An organisation's dashboard as one page (ADR-0024), its style and its
 * script inline, so it loads nothing at view time: a table of every
 * repository it shows, with its verdict, its score and the trend of it as a
 * sparkline, the trees below their floors, the time the gate saved it over
 * the last 30 days and when its latest verdict was; a repository that
 * published nothing says why. The script sorts the table by any column;
 * without it, the table reads in the order of the names. Everything a
 * repository published is its own word, and escaped.
 */
final readonly class DashboardPage
{
    private const string PAGE = <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>Mutation testing across %1$s</title>
        <style>%2$s</style>
        </head>
        <body>
        <main>
        <h1>Mutation testing across %1$s</h1>
        <p class="lead">%3$s</p>
        <table id="repositories">
        <thead>
        <tr>
        <th scope="col" data-sort="text">Repository</th>
        <th scope="col" data-sort="text">Verdict</th>
        <th scope="col" data-sort="number">Score</th>
        <th scope="col">Trend</th>
        <th scope="col" data-sort="number">Trees below their floor</th>
        <th scope="col" data-sort="number">Saved in 30 days</th>
        <th scope="col" data-sort="text">Latest verdict</th>
        </tr>
        </thead>
        <tbody>
        %4$s
        </tbody>
        </table>
        </main>
        <script>%5$s</script>
        </body>
        </html>

        HTML;

    private const string LEAD = '%d %s, as of %s.';

    private const string ROW = <<<'HTML'
        <tr>
        <th scope="row" data-value="%1$s">%2$s</th>
        <td data-value="%3$s" class="verdict %3$s">%3$s</td>
        <td data-value="%4$s">%5$s</td>
        <td>%6$s</td>
        <td data-value="%7$d">%8$s</td>
        <td data-value="%9$s">%10$s</td>
        <td data-value="%11$s">%11$s</td>
        </tr>
        HTML;

    private const string UNPUBLISHED = <<<'HTML'
        <tr class="unpublished">
        <th scope="row" data-value="%1$s">%2$s</th>
        <td colspan="6" data-value="">%3$s</td>
        </tr>
        HTML;

    private const string LINK = '<a href="%s">%s</a>';

    private const string PRIVATE = ' <span class="private">private</span>';

    private const string BELOW = '<li><code>%s</code> %s, below %s</li>';

    public static function html(Dashboard $dashboard, string $style, string $script): string
    {
        $rows = [];

        foreach ($dashboard->listings() as $listing) {
            $published = $listing->published();
            $rows[] = $published instanceof CannotJudge
                ? sprintf(
                    self::UNPUBLISHED,
                    Html::text($listing->name()),
                    self::name($listing),
                    Html::text($published->why()),
                )
                : self::row($listing, $published);
        }

        $count = count($dashboard->listings());

        return sprintf(
            self::PAGE,
            Html::text($dashboard->organisation()),
            $style,
            Html::text(sprintf(
                self::LEAD,
                $count,
                $count === 1 ? 'repository' : 'repositories',
                $dashboard->time()->value(),
            )),
            implode("\n", $rows),
            $script,
        );
    }

    private static function row(Listing $listing, Published $published): string
    {
        $score = $published->score();
        $saved = $published->saved();
        $below = $published->belowFloor();
        $trees = [];

        foreach ($below as $tree) {
            $trees[] = sprintf(
                self::BELOW,
                Html::text($tree->path()),
                Percent::of($tree->score()),
                Percent::of($tree->floor()),
            );
        }

        return sprintf(
            self::ROW,
            Html::text($listing->name()),
            self::name($listing),
            $published->verdict()->value,
            $score instanceof Score ? sprintf('%.2f', $score->percent()) : '',
            Percent::of($score),
            TrendSvg::of($listing->trend()),
            count($below),
            $trees === [] ? 'none' : sprintf('<ul>%s</ul>', implode('', $trees)),
            $saved instanceof Seconds ? sprintf('%.0f', $saved->seconds()) : '',
            $saved instanceof Seconds ? $saved->text() : 'no history yet',
            $published->time()->value(),
        );
    }

    /** A repository's name, linked where GitHub gave an address over HTTPS, and marked where it is private. */
    private static function name(Listing $listing): string
    {
        $name = Html::text($listing->name());
        $linked = str_starts_with($listing->url(), Origin::SECURE)
            ? sprintf(self::LINK, Html::text($listing->url()), $name)
            : $name;

        return $listing->isPrivate() ? sprintf('%s%s', $linked, self::PRIVATE) : $linked;
    }
}
