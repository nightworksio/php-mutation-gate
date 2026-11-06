<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\SavedLately;
use NightWorksIO\MutationGate\Core\Http\Origin;
use NightWorksIO\MutationGate\Core\Report\Page\Html;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Report\TrendSvg;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;

use function sprintf;
use function str_starts_with;

/**
 * An organisation's dashboard as one page (ADR-0024), its style and its
 * script inline, so it loads nothing at view time, and a Content Security
 * Policy that lets it run those alone: a table of every
 * repository it shows, with its verdict, its score and the trend of it as a
 * sparkline, the trees below their floors, the time the gate saved it in
 * its savings window and when its latest verdict was; a repository that
 * published nothing says why. The script sorts the table by any column;
 * without it, the table reads in the order of the names. Everything a
 * repository published is its own word, and escaped.
 *
 * @internal
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
        <meta http-equiv="Content-Security-Policy" content="%6$s">
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
        <th scope="col" data-sort="number">Saved</th>
        <th scope="col" data-sort="text">Latest verdict</th>
        </tr>
        </thead>
        %4$s
        </table>
        </main>
        <script>%5$s</script>
        </body>
        </html>

        HTML;

    private const string LEAD = '%d %s, as of %s.';

    private const string SAVED_IN = '%s in %s';

    private const string ROW = <<<'HTML'
        <tbody>
        <tr>
        <th scope="row" data-value="%1$s">%2$s</th>
        <td data-value="%3$s" class="verdict %3$s">%3$s</td>
        <td data-value="%4$s">%5$s</td>
        <td>%6$s</td>
        <td data-value="%7$d">%8$s</td>
        <td data-value="%9$s">%10$s</td>
        <td data-value="%11$s">%11$s</td>
        </tr>
        %12$s%13$s</tbody>
        HTML;

    private const string UNPUBLISHED = <<<'HTML'
        <tbody>
        <tr class="unpublished">
        <th scope="row" data-value="%1$s">%2$s</th>
        <td colspan="6" data-value="">%3$s</td>
        </tr>
        </tbody>
        HTML;

    /** The costliest mutators a repository named, collapsed under its row (ADR-0076). */
    private const string MUTATORS = <<<'HTML'
        <tr class="mutators">
        <td colspan="7"><details><summary>Costliest mutators</summary>
        <table>
        <thead>
        <tr>
        <th scope="col">Runner</th>
        <th scope="col">Mutator</th>
        <th scope="col">Time</th>
        <th scope="col">Kill rate</th>
        <th scope="col">Survivors</th>
        </tr>
        </thead>
        <tbody>
        %s
        </tbody>
        </table>
        </details></td>
        </tr>

        HTML;

    private const string MUTATOR = '<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%d</td></tr>';

    /** The lowest-scoring files a repository named, collapsed under its row (ADR-0100). */
    private const string FILES = <<<'HTML'
        <tr class="files">
        <td colspan="7"><details><summary>Lowest-scoring files</summary>
        <table>
        <thead>
        <tr>
        <th scope="col">File</th>
        <th scope="col">Score</th>
        <th scope="col">Against the base</th>
        <th scope="col">Not killed</th>
        </tr>
        </thead>
        <tbody>
        %s
        </tbody>
        </table>
        </details></td>
        </tr>

        HTML;

    private const string FILE = '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%d</td></tr>';

    /** What the page may load and run: its own style and script, by their hashes, and nothing else. */
    private const string POLICY = "default-src 'none'; style-src '%s'; script-src '%s'";

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
            sprintf(self::POLICY, Html::source($style), Html::source($script)),
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
            $saved instanceof SavedLately ? sprintf('%.0f', $saved->saved()->seconds()) : '',
            $saved instanceof SavedLately
                ? sprintf(self::SAVED_IN, $saved->saved()->text(), $saved->window()->said())
                : 'no history yet',
            $published->time()->value(),
            self::mutators($published),
            self::files($published),
        );
    }

    /** The lowest-scoring files a repository named, collapsed; nothing where it named none. */
    private static function files(Published $published): string
    {
        $rows = [];

        foreach ($published->files() as $file) {
            $score = $file->score();
            $base = $file->base();
            $rows[] = sprintf(
                self::FILE,
                Html::text($file->path()),
                Percent::of($score),
                $score instanceof Score && $base instanceof Score ? Percent::change($base, $score) : '',
                $file->notKilled(),
            );
        }

        return $rows === [] ? '' : sprintf(self::FILES, implode("\n", $rows));
    }

    /** The costliest mutators a repository named, collapsed; nothing where it named none. */
    private static function mutators(Published $published): string
    {
        $rows = [];

        foreach ($published->mutators() as $mutator) {
            $killRate = $mutator->killRate();
            $rows[] = sprintf(
                self::MUTATOR,
                Html::text($mutator->runner()),
                Html::text($mutator->mutator()),
                $mutator->seconds()->text(),
                $killRate instanceof Percentage ? sprintf('%s%%', $killRate->written()) : '-',
                $mutator->survived(),
            );
        }

        return $rows === [] ? '' : sprintf(self::MUTATORS, implode("\n", $rows));
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
