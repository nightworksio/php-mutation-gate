<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function is_int;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\EncodedReport;
use NightWorksIO\MutationGate\Core\Report\Page\PageShows;
use NightWorksIO\MutationGate\Core\Report\Page\ReportPage;
use NightWorksIO\MutationGate\Core\Report\ReportsTheEncoded;
use NightWorksIO\MutationGate\Core\Report\Stryker;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

/**
 * The reporter `html`: into the directory its entry's `path` names,
 * `index.html`, the gate's own page of the verdict, rendered from the JSON
 * report with its style and script inlined, so it loads nothing from a
 * network, and the report in the `mutation-testing-report-schema` format as
 * `mutation-report.json` (ADR-0009).
 *
 * @internal
 */
final readonly class HtmlReportDirectory implements Configurable, Reporter, ReportsTheEncoded
{
    public const string PAGE = 'index.html';

    public const string REPORT = 'mutation-report.json';

    private const string STYLE = 'page.css';

    private const string SCRIPT = 'page.js';

    private const string MISSING
        = 'The HTML report was not written: %s holds no page.css or page.js, which the page is styled and run by.';

    private const string UNNAMED = 'The HTML report is written to a directory, whose `path` the entry names.';

    private function __construct(
        private ReportPath $path,
        private Directory $project,
        private ReportPath $page,
        private PageShows $shows,
    ) {
    }

    /**
     * A report into a directory, with the project's files read from where the gate runs and the page's from here,
     * listing as much up front as the page does by default, or as these say.
     */
    public static function at(
        string $path,
        string $project,
        string $page,
        PageShows|NotGiven $shows = new NotGiven(),
    ): self {
        return new self(
            ReportPath::at($path),
            Directory::at($project),
            ReportPath::at($page),
            $shows instanceof PageShows ? $shows : PageShows::standard(),
        );
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = ReportPath::named($options, self::UNNAMED);
        $standard = PageShows::standard();
        $files = $options->integer(Key::of(PageShows::FILES));
        $survivors = $options->integer(Key::of(PageShows::SURVIVORS));

        return $path instanceof Invalid ? $path : new self(
            $path,
            Directory::at('.'),
            ReportPath::at(Resources::at(Shipped::ReportPage)),
            PageShows::of(
                is_int($files) ? $files : $standard->files(),
                is_int($survivors) ? $survivors : $standard->survivors(),
            ),
        );
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->reportFrom($verdict, EncodedReport::of($verdict));
    }

    public function reportFrom(Verdict $verdict, EncodedReport $report): Written|NotWritten
    {
        $style = $this->page->file(self::STYLE)->read();
        $script = $this->page->file(self::SCRIPT)->read();

        if ($style === '' || $script === '') {
            return NotWritten::because(sprintf(self::MISSING, $this->page->value()));
        }

        $stryker = Stryker::json($verdict, MutatedFiles::in($this->project)->of($verdict->trees()->mutants()));
        $written = $this->path->file(self::REPORT)->write($stryker);

        return $written instanceof NotWritten
            ? $written
            : $this->path->file(self::PAGE)->write(ReportPage::html($report->data(), $style, $script, $this->shows));
    }
}
