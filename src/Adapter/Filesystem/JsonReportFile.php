<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\EncodedReport;
use NightWorksIO\MutationGate\Core\Report\ReportsTheEncoded;
use NightWorksIO\MutationGate\Core\Report\ReportTests;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

/**
 * The reporter `json`: the gate's own JSON report (`"format": 1`), written to
 * the `path` its entry names (ADR-0009), each mutant listing the tests its
 * `tests` says: `all`, by default, `killers` or `none`.
 *
 * @internal
 */
final readonly class JsonReportFile implements Configurable, Reporter, ReportsTheEncoded
{
    private function __construct(private ReportPath $path, private ReportTests $tests)
    {
    }

    public static function at(string $path): self
    {
        return new self(ReportPath::at($path), ReportTests::All);
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = ReportPath::ofFile($options, 'The JSON report');
        $tests = $options->text(Key::of(ReportTests::OPTION));
        $listed = is_string($tests) ? ReportTests::tryFrom($tests) : ReportTests::All;

        return match (true) {
            $path instanceof Invalid => $path,
            $tests instanceof Problem => Invalid::because($tests),
            ! $listed instanceof ReportTests => Invalid::because(
                Problem::mismatch(ReportTests::OPTION, '"all", "killers" or "none"', sprintf('"%s"', (string) $tests)),
            ),
            default => new self($path, $listed),
        };
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->reportFrom($verdict, EncodedReport::of($verdict));
    }

    public function reportFrom(Verdict $verdict, EncodedReport $report): Written|NotWritten
    {
        return $this->path->write(
            $this->tests === ReportTests::All
                ? $report->text()
                : JsonText::encode($this->tests->trimmed($report->data())),
        );
    }
}
