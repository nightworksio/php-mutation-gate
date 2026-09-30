<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/**
 * What a static analyser is asked about one mutant (ADR-0020, decision 6):
 * the mutant, analysed in place of its original file; the files that depend
 * on the original, analysed again, unchanged, against the mutant; and the
 * variables the analyser runs without, which are the runner's.
 */
final readonly class MutantCheck
{
    private function __construct(
        private Path $original,
        private Path $mutant,
        private Paths $dependents,
        private Withheld $withheld,
    ) {
    }

    /** A mutant of this original, with no dependents, run without what every run withholds. */
    public static function of(Path $original, Path $mutant): self
    {
        return new self($original, $mutant, Paths::none(), Withheld::standard());
    }

    public function withDependents(Paths $dependents): self
    {
        return clone($this, ['dependents' => $dependents]);
    }

    public function withholding(Withheld $withheld): self
    {
        return clone($this, ['withheld' => $withheld]);
    }

    /** The file the mutant stands in for. */
    public function original(): Path
    {
        return $this->original;
    }

    public function mutant(): Path
    {
        return $this->mutant;
    }

    /** The files analysed again, unchanged, against the mutant, where it can break them. */
    public function dependents(): Paths
    {
        return $this->dependents;
    }

    public function withheld(): Withheld
    {
        return $this->withheld;
    }
}
