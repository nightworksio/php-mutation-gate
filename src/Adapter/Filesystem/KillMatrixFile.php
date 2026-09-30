<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\KillMatrixCsv;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * The reporter `kill-matrix`: the kill matrix as CSV, one record per mutant and
 * covering test, streamed to the `path` its entry names (ADR-0014, decision 9).
 */
final readonly class KillMatrixFile implements Configurable, Reporter
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
        $path = ReportPath::ofFile($options, 'The kill matrix');

        return $path instanceof Invalid ? $path : new self($path);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->path->stream(KillMatrixCsv::records($verdict));
    }
}
