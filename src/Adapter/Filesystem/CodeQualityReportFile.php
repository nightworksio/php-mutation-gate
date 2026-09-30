<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\CodeQuality;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * The reporter `gitlab`: GitLab's Code Quality JSON, an issue per mutant counted
 * as not killed, written to the `path` its entry names (ADR-0016, decision 5).
 */
final readonly class CodeQualityReportFile implements Configurable, Reporter
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
        $path = ReportPath::ofFile($options, 'The GitLab Code Quality report');

        return $path instanceof Invalid ? $path : new self($path);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->path->write(CodeQuality::json($verdict));
    }
}
