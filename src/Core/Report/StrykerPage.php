<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function sprintf;
use function str_replace;

/**
 * The HTML report's page: Stryker's `mutation-testing-elements` viewer and the
 * report, both inlined, so the page loads nothing from a network. The report
 * holds text the project under test controls, such as diffs and test names,
 * so it is embedded as JSON data with every `&`, `<` and `>` escaped, and
 * read with `JSON.parse`; nothing in it can end its element. The viewer's
 * licence (Apache-2.0) travels with it, in a comment above it.
 */
final readonly class StrykerPage
{
    /** The version of the viewer carried in `resources/mutation-testing-elements`. */
    public const string VIEWER = '3.9.0';
    /**
     * What stands in for each character of the report that could end its
     * script element or be read as markup, as JSON spells it inside a string,
     * the only place in JSON such a character can be.
     */
    private const array ESCAPES = ['\u0026', '\u003c', '\u003e', '\u2028', '\u2029'];
    private const string PAGE = <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>mutation-gate</title>
        </head>
        <body>
        <mutation-test-report-app title-postfix="mutation-gate"></mutation-test-report-app>
        <!--
        The viewer below is mutation-testing-elements %1$s, by the Stryker Mutator team, under this licence:

        %2$s
        -->
        <script>%3$s</script>
        <script type="application/json" id="report">%4$s</script>
        <script>
        const app = document.querySelector('mutation-test-report-app');
        function updateTheme() { document.body.style.backgroundColor = app.themeBackgroundColor; }
        app.addEventListener('theme-changed', updateTheme);
        updateTheme();
        app.report = JSON.parse(document.getElementById('report').textContent);
        </script>
        </body>
        </html>

        HTML;

    public static function html(string $report, string $viewer, string $licence): string
    {
        return sprintf(
            self::PAGE,
            self::VIEWER,
            str_replace('--', '- -', $licence),
            str_replace(['</script', '<!--'], ['<\/script', '\x3C!--'], $viewer),
            str_replace(['&', '<', '>', "\u{2028}", "\u{2029}"], self::ESCAPES, $report),
        );
    }
}
