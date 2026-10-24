<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Page;

use function array_slice;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Report\MutantJson;
use NightWorksIO\MutationGate\Core\Report\Mutator;

use function sprintf;

/**
 * The HTML report's survivors: each mutant the score counts as not killed,
 * those on changed lines first, by where it is, its mutator and what it came
 * to, with what test would catch it, each a link to it in the files below.
 * A run with none says so.
 *
 * @phpstan-import-type MutantEntry from MutantJson
 */
final readonly class Survivors
{
    /** How many survivors the overview names; the files below hold every one. */
    public const int SHOWN = 25;

    private const string CARD = <<<'HTML'
        <section class="card survivors" aria-labelledby="survivors-title">
        <h2 id="survivors-title">Survivors <span class="count">%d</span></h2>
        %s
        </section>
        HTML;

    private const string LIST = '<ol class="survivor-list">%s</ol>%s';

    private const string ITEM = <<<'HTML'
        <li><a href="#m-%1$s"><code>%2$s:%3$d</code></a>
        <span class="mutator" title="%4$s">%9$s</span>
        <span class="tag tag--%5$s">%6$s</span>
        <span class="judgement">%7$s</span>%8$s
        <p class="hint">%10$s</p></li>
        HTML;

    private const string CHANGED = ' <span class="chip">changed line</span>';

    private const string MORE = '<p class="aside">And %d more, each in its file below.</p>';

    private const string NONE = '<p class="empty">No survivors: every mutant the score counts was killed.</p>';

    private const string NOTHING = '<p class="empty">No mutant was judged.</p>';

    public static function html(Mutants $mutants): string
    {
        $survivors = $mutants->survivors();
        $items = [];

        foreach (array_slice($survivors, 0, self::SHOWN) as $mutant) {
            $items[] = self::item($mutant);
        }

        $shown = match (true) {
            $mutants->count() === 0 => self::NOTHING,
            $survivors === [] => self::NONE,
            default => sprintf(self::LIST, implode('', $items), self::more(count($survivors))),
        };

        return sprintf(self::CARD, count($survivors), $shown);
    }

    /** @param MutantEntry $mutant */
    private static function item(array $mutant): string
    {
        $outcome = Mutants::outcomeOf($mutant);

        return sprintf(
            self::ITEM,
            Html::text($mutant['id']),
            Html::text($mutant['file']),
            $mutant['line'],
            Html::text($mutant['mutator']),
            $outcome->value,
            $outcome->label(),
            Html::text($mutant['judgement']),
            $mutant['changedLine'] ? self::CHANGED : '',
            Html::text(Mutator::short($mutant['mutator'])),
            Html::prose(Hint::lead($mutant['hint'])),
        );
    }

    private static function more(int $survivors): string
    {
        return $survivors > self::SHOWN ? sprintf(self::MORE, $survivors - self::SHOWN) : '';
    }
}
