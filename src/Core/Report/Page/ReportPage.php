<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use NightWorksIO\MutationGate\Core\Report\JsonReport;

use function sprintf;
use function str_replace;

/**
 * The HTML report: one page that holds everything it shows, its style and
 * its script inline, so it loads nothing from a network (ADR-0009). Its top
 * says the verdict and why, the counts and each set against its floor, the
 * survivors and the files they are in, and where the verdict comes from;
 * all of it is read without a script. Below, a list of the files beside a
 * pane of the one chosen, each mutant with what it changed, what it came to
 * and the test that killed it or that none did, which the script draws from
 * the page's data, each file's mutants as the file is first shown (see
 * Navigation). Every part is rendered from the JSON report, so every reader
 * of a run reads one verdict.
 *
 * @phpstan-import-type Report from JsonReport
 */
final readonly class ReportPage
{
    private const string PAGE = <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>mutation-gate: %1$s</title>
        <style>%2$s</style>
        </head>
        <body>
        %3$s
        <main class="overview">
        %4$s
        %5$s
        %6$s
        </main>
        %7$s
        <section class="depth" id="depth" aria-labelledby="depth-title">
        <div class="depth-head">
        <h2 id="depth-title">Each file</h2>
        <fieldset class="modes" hidden>
        <legend>Show each change as</legend>
        <label><input type="radio" name="mode" value="lines" checked> the lines it changed</label>
        <label><input type="radio" name="mode" value="unified"> a diff with context</label>
        <label><input type="radio" name="mode" value="split"> side by side</label>
        </fieldset>
        </div>
        <noscript><p class="empty">
        Each file's mutants are shown with JavaScript. The overview above holds the verdict.
        </p></noscript>
        <div class="depth-body" hidden>
        <nav class="file-nav" aria-label="Files">
        <label class="file-pick">File <select id="file-pick"></select></label>
        <ol id="file-list"></ol>
        </nav>
        <article class="pane" id="pane" tabindex="-1"></article>
        </div>
        </section>
        %8$s
        <script type="application/json" id="data">%9$s</script>
        %10$s
        <script>%11$s</script>
        </body>
        </html>

        HTML;

    /** @param Report $report */
    public static function html(array $report, string $style, string $script): string
    {
        $mutants = Mutants::of($report);
        $glossary = Glossary::of($report);
        $navigation = Navigation::of($report, $mutants, $glossary);

        return sprintf(
            self::PAGE,
            Html::text($report['judgement']),
            str_replace('</style', '<\/style', $style),
            Headline::html($report, $glossary),
            Figures::html($report, $glossary),
            Survivors::html($mutants),
            Files::html($mutants),
            Origin::html($report),
            Details::html($report),
            $navigation->index(),
            $navigation->packed(),
            str_replace('</script', '<\/script', $script),
        );
    }
}
