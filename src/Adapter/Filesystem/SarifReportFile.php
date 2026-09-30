<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function getcwd;
use function getenv;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Sarif;
use NightWorksIO\MutationGate\Core\Report\SourceRoot;
use NightWorksIO\MutationGate\Core\Report\Unrooted;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * The reporter `sarif`: SARIF 2.1.0, for code scanning, written to the `path`
 * its entry names (ADR-0009, decision 2). Outside CI it also names the
 * repository's root on this machine, for an editor's viewer (ADR-0015,
 * decision 9).
 */
final readonly class SarifReportFile implements Configurable, Reporter
{
    private function __construct(private ReportPath $path, private SourceRoot|Unrooted $root)
    {
    }

    /** The report as CI uploads it, naming no root. */
    public static function at(string $path): self
    {
        return new self(ReportPath::at($path), Unrooted::report());
    }

    /** The report for an editor, naming this absolute directory as the repository's root. */
    public static function rootedAt(string $path, string $root): self
    {
        return new self(ReportPath::at($path), SourceRoot::at($root));
    }

    /** From its entry's `path`; in a run with `CI` unset, rooted at the directory the run started in. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $path = ReportPath::ofFile($options, 'The SARIF report');
        $here = getenv('CI') === false || getenv('CI') === '' ? getcwd() : false;

        return match (true) {
            $path instanceof Invalid => $path,
            $here === false => new self($path, Unrooted::report()),
            default => new self($path, SourceRoot::at($here)),
        };
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->path->write(
            $this->root instanceof SourceRoot ? Sarif::rootedAt($verdict, $this->root) : Sarif::json($verdict),
        );
    }
}
