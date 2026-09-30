<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function array_key_exists;
use function dirname;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Stryker;
use NightWorksIO\MutationGate\Core\Report\StrykerPage;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

/**
 * The reporter `html`: into the directory its entry's `path` names, the
 * report in the `mutation-testing-report-schema` format as
 * `mutation-report.json`, and `index.html`, a page that shows it with
 * Stryker's viewer inlined, so it loads nothing from a network
 * (ADR-0009, decision 4).
 */
final readonly class HtmlReportDirectory implements Configurable, Reporter
{
    public const string PAGE = 'index.html';

    public const string REPORT = 'mutation-report.json';

    private const string SCRIPT = 'mutation-test-elements.js';

    private const string LICENCE = 'LICENSE';

    /** How far above this file the package's root is. */
    private const int PACKAGE_ROOT = 3;

    private const string MISSING = 'The HTML report was not written: %s is missing, so the page would have no viewer.';

    private const string UNNAMED = 'The HTML report is written to a directory, whose `path` the entry names.';

    private function __construct(private ReportPath $path, private Directory $project, private ReportPath $viewer)
    {
    }

    /** A report into a directory, with the project's files read from where the gate runs and the viewer from here. */
    public static function at(string $path, string $project, string $viewer): self
    {
        return new self(ReportPath::at($path), Directory::at($project), ReportPath::at($viewer));
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = ReportPath::from($options, '', self::UNNAMED);

        return $path instanceof Invalid ? $path : new self(
            $path,
            Directory::at('.'),
            ReportPath::at(sprintf('%s/resources/mutation-testing-elements', dirname(__DIR__, self::PACKAGE_ROOT))),
        );
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $script = $this->viewer->file(self::SCRIPT)->read();
        $licence = $this->viewer->file(self::LICENCE)->read();

        if ($script === '' || $licence === '') {
            return NotWritten::because(sprintf(self::MISSING, $this->viewer->value()));
        }

        $report = Stryker::json($verdict, $this->sources($verdict));
        $written = $this->path->file(self::REPORT)->write($report);

        return $written instanceof NotWritten
            ? $written
            : $this->path->file(self::PAGE)->write(StrykerPage::html($report, $script, $licence));
    }

    /** @return array<string, Contents> each mutated file that can be read, by its path */
    private function sources(Verdict $verdict): array
    {
        $sources = [];

        foreach ($verdict->trees()->mutants() as $judged) {
            $file = $judged->mutant()->location()->file();
            $contents = array_key_exists($file->value(), $sources) ? Missing::at($file) : $this->project->read($file);

            if ($contents instanceof Contents) {
                $sources[$file->value()] = $contents;
            }
        }

        return $sources;
    }
}
