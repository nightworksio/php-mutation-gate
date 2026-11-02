<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_slice;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Report\MutantJson;

use function sprintf;

/**
 * The HTML report's files: how many files the run judged, how many of its
 * mutants are on changed lines and how many of those survive, and the files
 * with the most survivors, each a link to it below.
 *
 * @internal
 *
 * @phpstan-import-type MutantEntry from MutantJson
 * @phpstan-import-type FileEntry from Mutants
 */
final readonly class Files
{
    /** How many files the overview names; the files below list every one. */
    public const int SHOWN = 8;

    private const string CARD = <<<'HTML'
        <section class="card files" aria-labelledby="files-title">
        <h2 id="files-title">Files judged <span class="count">%d</span></h2>
        <p class="aside">%s</p>
        %s
        </section>
        HTML;

    private const string CHANGED = '%d mutants on changed lines, %d of them surviving.';

    private const string UNCHANGED = 'No mutant is on a changed line.';

    private const string LIST = '<ol class="file-list">%s</ol>';

    private const string ITEM = <<<'HTML'
        <li><a href="#f-%d"><code>%s</code></a> <span class="tag tag--escaped">%d surviving</span></li>
        HTML;

    private const string CLEAN = '<p class="empty">No file has a survivor.</p>';

    public static function html(Mutants $mutants): string
    {
        $files = $mutants->files();
        $items = [];

        foreach (array_slice($files, 0, self::SHOWN) as $at => $file) {
            $items[] = $file['survivors'] > 0
                ? sprintf(self::ITEM, $at, Html::text($file['path']), $file['survivors'])
                : '';
        }

        $listed = implode('', $items);

        return sprintf(
            self::CARD,
            count($files),
            self::changed($mutants),
            $listed === '' ? self::CLEAN : sprintf(self::LIST, $listed),
        );
    }

    private static function changed(Mutants $mutants): string
    {
        $changed = $mutants->onChangedLines();
        $surviving = 0;

        foreach ($mutants->survivors() as $survivor) {
            $surviving += $survivor['changedLine'] ? 1 : 0;
        }

        return $changed === 0 ? self::UNCHANGED : sprintf(self::CHANGED, $changed, $surviving);
    }
}
