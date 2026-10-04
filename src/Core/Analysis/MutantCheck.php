<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * What a static analyser is asked about one mutant (ADR-0020, decision 6):
 * the mutant, analysed in place of its original file; the files that depend
 * on the original, analysed again, unchanged, against the mutant; the
 * variables the analyser runs without, which are the runner's; and how long
 * the check may take, `staticCheck.seconds`.
 */
final readonly class MutantCheck
{
    private function __construct(
        private Path $original,
        private Path $mutant,
        private Paths $dependents,
        private Withheld $withheld,
        private Seconds|Unlimited $limit,
    ) {
    }

    /** A mutant of this original, with no dependents, run without what every run withholds, for as long as it takes. */
    public static function of(Path $original, Path $mutant): self
    {
        return new self($original, $mutant, Paths::none(), Withheld::standard(), Unlimited::time());
    }

    public function withDependents(Paths $dependents): self
    {
        return clone($this, ['dependents' => $dependents]);
    }

    public function withholding(Withheld $withheld): self
    {
        return clone($this, ['withheld' => $withheld]);
    }

    /** This check, which may take this long. */
    public function within(Seconds $limit): self
    {
        return clone($this, ['limit' => $limit]);
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

    /** How long the check may take, the language server's start among it; past that, it cannot judge. */
    public function limit(): Seconds|Unlimited
    {
        return $this->limit;
    }
}
