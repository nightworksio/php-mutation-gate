<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Sarif;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * The reporter `sarif`: SARIF 2.1.0, for code scanning, written to the `path`
 * its entry names (ADR-0009, decision 2).
 */
final readonly class SarifReportFile implements Configurable, Reporter
{
    private function __construct(private ReportPath $path)
    {
    }

    public static function at(string $path): self
    {
        return new self(ReportPath::at($path));
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = ReportPath::from($options, '', 'The SARIF report is written to a file, whose `path` the entry names.');

        return $path instanceof Invalid ? $path : new self($path);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->path->write(Sarif::json($verdict));
    }
}
