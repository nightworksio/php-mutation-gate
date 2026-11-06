<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_filter;
use function array_map;
use function array_merge;
use function array_slice;

use Closure;

use function count;
use function implode;
use function intdiv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\SavedLately;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\Obstacles;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * The blocks the sticky comment and the step summary are both made of, as GitHub Markdown: the verdict's head, the
 * trees, the sets, the ignored mutants, what kept the run from judging, the failures and warnings, and the floors
 * that can rise (ADR-0009).
 *
 * @internal
 */
final readonly class MarkdownBlocks
{
    private const string RAISE = 'Raise them with `%s`, and commit the baseline.';

    /**
     * A document showing this many entries of each list, or half as many each time until it fits, none at the last.
     *
     * @param Closure(int): string  $document the document showing this many entries of each list
     * @param Closure(string): bool $fits     whether a document fits
     */
    public static function fitted(Closure $document, int $shown, Closure $fits): string
    {
        do {
            $fitted = $document($shown);
            $tried = $shown;
            $shown = intdiv($shown, 2);
        } while (! $fits($fitted) && $tried > 0);

        return $fitted;
    }

    /** @param list<string> $blocks */
    public static function document(array $blocks): string
    {
        return sprintf("%s\n", implode("\n\n", array_filter($blocks, static fn(string $block): bool => $block !== '')));
    }

    /**
     * The verdict, what the run took and saved and what pruning left out, and the project's score.
     *
     * @return list<string>
     */
    public static function head(Verdict $verdict, Overview $overview, SavedLately|NoHistory $lately): array
    {
        return [
            $verdict->trees()->sample()->isDrawn()
                ? sprintf('## mutation-gate: %s, sampled', $verdict->judgement()->value)
                : sprintf('## mutation-gate: %s', $verdict->judgement()->value),
            SavingsText::of($verdict, $lately),
            implode(' ', [
                SetText::project($overview->score(), $verdict->trees()->standing()),
                ...$verdict->wasCutShort() ? [SetText::CUT_SHORT] : [],
                ...$verdict->trees()->sample()->isDrawn() ? [SetText::SAMPLED] : [],
            ]),
        ];
    }

    /** @return list<string> each tree's floor, score, change against the base and result, as one table */
    public static function trees(Verdict $verdict): array
    {
        $trees = ['| Tree | Floor | Score | Against the base | Result |', '|---|---|---|---|---|'];

        foreach ($verdict->trees() as $tree) {
            $trees[] = self::treeRow($tree);
        }

        return count($verdict->trees()) > 0 ? [implode("\n", $trees)] : [];
    }

    /** @return list<string> the new-code sets and each package's security set, then each owner group's score */
    public static function sets(Verdict $verdict): array
    {
        $sets = [];

        foreach ($verdict->sets()->newCode() as $set) {
            $sets[] = sprintf('- %s', Escape::text(SetText::newCode($set)));
        }

        foreach ($verdict->sets()->security() as $set) {
            $sets[] = sprintf('- %s', Escape::text(SetText::security($set)));
        }

        $owners = array_map(
            static fn(string $line): string => sprintf('- %s', Escape::text($line)),
            SetText::owned($verdict->sets()),
        );

        return [
            ...$sets === [] ? [] : [implode("\n", $sets)],
            ...$owners === [] ? [] : [implode("\n", $owners)],
        ];
    }

    /**
     * The mutants an ignore left out, up to this many, each with why (ADR-0008).
     *
     * @return list<string>
     */
    public static function ignored(Overview $overview, int $shown): array
    {
        $ignored = $overview->ignored();

        return MarkdownItems::ignored(array_slice($ignored, 0, $shown), count($ignored));
    }

    /** @return list<string> why the run could not judge; nothing where it judged */
    public static function obstacles(Verdict $verdict): array
    {
        return self::reasons($verdict->obstacles());
    }

    /** @return list<string> the failures, as one list; nothing where there are none */
    public static function failures(Verdict $verdict): array
    {
        return self::bullets($verdict->failures());
    }

    /** @return list<string> the warnings, as one list; nothing where there are none */
    public static function warnings(Verdict $verdict): array
    {
        return self::bullets($verdict->warnings());
    }

    /** @return list<string> the floors that can rise, then how to raise them; nothing where none can */
    public static function floors(Verdict $verdict): array
    {
        $raised = [];

        foreach (RisingFloors::of($verdict) as [$named, $floor]) {
            $raised[] = sprintf('- %s to %s', Escape::code($named), Percent::of($floor));
        }

        return $raised === [] ? [] : [
            implode("\n", $raised),
            sprintf(self::RAISE, $verdict->account()->provenance()->command()->baselineWritten()),
        ];
    }

    /**
     * A heading and its blocks; nothing where there are none.
     *
     * @param  list<string> $blocks
     * @return list<string>
     */
    public static function section(string $heading, array $blocks): array
    {
        return $blocks === [] ? [] : [sprintf('### %s', $heading), ...$blocks];
    }

    private static function treeRow(TreeVerdict $tree): string
    {
        $floor = $tree->floor();

        return sprintf(
            '| %s | %s | %s | %s | %s |',
            Escape::code($tree->tree()->path()->value()),
            SetText::floor($floor),
            $floor instanceof Exempt ? '' : SetText::score($tree),
            SetText::change($tree),
            Escape::text(
                $floor instanceof Exempt ? sprintf('exempt: %s', $floor->reason()) : $tree->judgement()->value,
            ),
        );
    }

    /** @return list<string> */
    private static function bullets(Failures|Warnings $items): array
    {
        $bullets = [];

        foreach ($items as $item) {
            $bullets[] = sprintf('- %s', Escape::text($item->text()));
        }

        return $bullets === [] ? [] : [implode("\n", $bullets)];
    }

    /**
     * Why the run could not judge, as one list, each set of tests that fails
     * without any mutant with the mutants it leaves out listed under it;
     * nothing where it judged.
     *
     * @return list<string>
     */
    private static function reasons(Obstacles $obstacles): array
    {
        $reasons = [
            ...array_map(
                static fn(CannotJudge $obstacle): string => sprintf('- %s', Escape::text($obstacle->why())),
                [...$obstacles->met()],
            ),
            ...array_merge(...array_map(FailingText::items(...), [...$obstacles->unmutatedFailures()])),
        ];

        return array_filter([implode("\n", $reasons)], static fn(string $list): bool => $list !== '');
    }
}
