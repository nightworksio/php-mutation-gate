<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/**
 * A static analyser, asked about one mutant at a time (ADR-0020, decision 6):
 * Mago, PHPStan, Psalm, or one an extension adds. A check that cannot run
 * leaves the mutant to its tests; it never kills it. Whether a mutant's
 * findings reject it is the core's to say.
 */
interface StaticChecker
{
    /** The analyser's name, its exact version, and a digest of the config it reads. */
    public function identity(): AnalyserIdentity|CannotJudge;

    /**
     * What the analyser reports about these original files, from one run over
     * them before any check, without the variables withheld from the runner.
     */
    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge;

    /**
     * What the analyser reports about a mutant, analysed in place of its
     * original file and of nothing else, so every finding belongs to it. It
     * runs without the variables withheld from the runner.
     */
    public function check(Path $original, Path $mutant, Withheld $withheld): Findings|CannotJudge;
}
