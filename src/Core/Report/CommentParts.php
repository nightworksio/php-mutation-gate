<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_filter;
use function array_slice;

use Closure;

use function count;
use function in_array;

use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Comment\Detail;
use NightWorksIO\MutationGate\Core\Comment\Lines;
use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * Each section of the sticky comment as the config shapes it (ADR-0102), as headed parts: the verdict, the security
 * survivors (ADR-0021), the trees, the sets, the files that fell (ADR-0100), the survivors on changed lines or on
 * every line, the unjudged and flaky mutants, the ignored ones, what kept the run from judging, the failures and
 * warnings, the floors that can rise, the line that links the HTML report, and what the run cost. Where the config
 * leaves the problems out, the verdict still says why a run could not judge.
 *
 * @internal
 */
final readonly class CommentParts
{
    /**
     * @param list<JudgedMutant> $listed the survivors of no security set
     */
    private function __construct(
        private Verdict $verdict,
        private CommentShows $shows,
        private Overview $overview,
        private array $listed,
        private ItemShown $item,
    ) {
    }

    public static function of(Verdict $verdict, CommentShows $shows): self
    {
        $overview = Overview::of($verdict);
        $listed = [...array_filter(
            [...$overview->survivors()],
            static fn(JudgedMutant $mutant): bool => ! $overview->isSecurity($mutant),
        )];

        return new self($verdict, $shows, $overview, $listed, ItemShown::by($shows));
    }

    /**
     * A section's parts, each a heading, empty where the part has none, and its blocks.
     *
     * @param  Closure(Cap): int                  $shown how many entries of each list the comment shows
     * @return list<array{string, list<string>}>
     */
    public function section(Section $section, Closure $shown): array
    {
        return match ($section) {
            Section::Verdict => $this->verdict(),
            Section::Security => $this->security($shown(Cap::Security)),
            Section::Trees => [['', MarkdownBlocks::trees($this->verdict)]],
            Section::Sets => [['', MarkdownBlocks::sets($this->verdict)]],
            Section::Heatmap => $this->heatmap(),
            Section::Survivors => $this->survivors($shown(Cap::Survivors)),
            Section::Unjudged => $this->unjudged($shown(Cap::Unjudged)),
            Section::Ignored => [[
                sprintf('Ignored (%d)', count($this->overview->ignored())),
                MarkdownBlocks::ignored($this->overview, $shown(Cap::Ignored)),
            ]],
            Section::Problems => [
                ['Cannot judge', MarkdownBlocks::obstacles($this->verdict)],
                ['Failures', MarkdownBlocks::failures($this->verdict)],
                ['Warnings', MarkdownBlocks::warnings($this->verdict)],
            ],
            Section::Floors => [[Section::Floors->title(), MarkdownBlocks::floors($this->verdict)]],
            Section::Report => [['', [Markdown::REPORT_SLOT]]],
            Section::Costs => [['', [CostText::of($this->verdict)]]],
        };
    }

    /** @return list<array{string, list<string>}> */
    private function verdict(): array
    {
        $head = [['', MarkdownBlocks::head($this->verdict, $this->overview, NoHistory::yet())]];

        return in_array(Section::Problems, [...$this->shows->sections()], strict: true)
            ? $head
            : [...$head, ['Cannot judge', MarkdownBlocks::obstacles($this->verdict)]];
    }

    /** @return list<array{string, list<string>}> */
    private function security(int $shown): array
    {
        $security = $this->overview->securitySurvivors();
        $secured = Folded::of($security, $this->verdict->trees()->clusters());

        return [[
            sprintf('Security survivors (%d)', count($security)),
            MarkdownItems::details(
                array_slice($secured, 0, $shown),
                count($secured),
                $this->overview->gate(),
                $this->item,
            ),
        ]];
    }

    /** @return list<array{string, list<string>}> */
    private function heatmap(): array
    {
        $fallen = $this->verdict->account()->heatmap()->fallen($this->shows);

        return [[
            sprintf('Files that fell, or sit below their floor (%d)', count($fallen)),
            HeatmapMarkdown::table($fallen),
        ]];
    }

    /** @return list<array{string, list<string>}> */
    private function survivors(int $shown): array
    {
        $all = $this->shows->lines() === Lines::All;
        $survivors = [...array_filter(
            $this->listed,
            static fn(JudgedMutant $mutant): bool => Markdown::isSettled($mutant)
                && ($all || $mutant->isOnChangedLine()),
        )];
        $gate = $this->overview->gate();
        $item = $this->item;
        $rows = $this->shows->detail() === Detail::Row;

        return [[
            sprintf($all ? 'Survivors (%d)' : 'Survivors on changed lines (%d)', count($survivors)),
            MarkdownItems::owned(
                $this->verdict->sets()->owners(),
                $survivors,
                Folded::of($survivors, $this->verdict->trees()->clusters()),
                $shown,
                static fn(array $items, int $of): array => $rows
                    ? MarkdownItems::table($items, $of, $gate, $item)
                    : MarkdownItems::details($items, $of, $gate, $item),
                OwnerMentions::headings($this->verdict->sets()->owners()),
            ),
        ]];
    }

    /** @return list<array{string, list<string>}> */
    private function unjudged(int $shown): array
    {
        $other = [...array_filter(
            $this->listed,
            static fn(JudgedMutant $mutant): bool => ! Markdown::isSettled($mutant),
        )];

        return [[
            sprintf('Unjudged and flaky (%d)', count($other)),
            MarkdownItems::table(array_slice($other, 0, $shown), count($other), $this->overview->gate(), $this->item),
        ]];
    }
}
