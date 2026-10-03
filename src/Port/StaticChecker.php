<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/**
 * A static analyser, asked about one mutant at a time (ADR-0020, decision 6):
 * Mago, PHPStan, Psalm, or one an extension adds. Every process it starts
 * runs without the variables withheld from the runner (decision 18). A check
 * that cannot run leaves the mutant to its tests; it never kills it. Whether
 * a mutant's findings reject it is the core's to say.
 */
interface StaticChecker
{
    /** The analyser's name, its exact version, and a digest of the config it reads. */
    public function identity(Withheld $withheld): AnalyserIdentity|CannotJudge;

    /**
     * What the analyser reports about these original files, from one run
     * over them before any check, each finding in the file it sits in, as
     * the project spells it.
     */
    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge;

    /**
     * What the analyser reports about a mutant, analysed in place of its
     * original file, and about the dependents the check lists, analysed
     * unchanged against it. Each finding names the file it sits in, as the
     * project spells it: the original, where it sits in the mutant, or
     * another file the analyser analyses again against the mutant. An
     * original outside the paths the analyser analyses is out of its scope,
     * and the mutant is left unchecked.
     */
    public function check(MutantCheck $check): Findings|OutOfScope|CannotJudge;
}
