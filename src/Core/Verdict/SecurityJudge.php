<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;

/**
 * Judges each package's security set (ADR-0021, decisions 16 and 17): the
 * mutants of its trees the security mutators made, held to the higher of
 * the floor its manifest declares, or else `security.floor`, and its
 * baseline entry's. A package is judged where it holds a security mutant or
 * the baseline holds its set; where none is, one empty set at the root
 * passes and says so. There is no set where no mutator makes security
 * mutants.
 */
final readonly class SecurityJudge
{
    private function __construct(
        private Baseline $baseline,
        private Uncovered $uncovered,
        private Floor|Undeclared $floor,
    ) {
    }

    /** A judge reading the baseline's security entries, holding a set no manifest declares a floor for to this one. */
    public static function of(Baseline $baseline, Uncovered $uncovered, Floor|Undeclared $floor): self
    {
        return new self($baseline, $uncovered, $floor);
    }

    public function judged(TreeVerdicts $verdicts, NamedMutators $mutators): SecurityVerdicts
    {
        $judged = [];

        foreach ($this->grouped($verdicts, $mutators) as [$package, $mutants]) {
            $recorded = $this->baseline->securityOf($package->path());
            $judged = $mutants->count() > 0 || $recorded instanceof Entry
                ? [...$judged, $this->secured($package, $mutants, $recorded)]
                : $judged;
        }

        return match (true) {
            [...$mutators] === [] => SecurityVerdicts::none(),
            $judged === [] => SecurityVerdicts::of(
                $this->secured(Package::at(Path::root()), JudgedMutants::none(), Unrecorded::floor()),
            ),
            default => SecurityVerdicts::of(...$judged),
        };
    }

    /**
     * Each package of the trees, as one that declares a security floor gives
     * it, with the mutants of its trees these mutators made.
     *
     * @return array<string, array{Package, JudgedMutants}> by the package's path
     */
    private function grouped(TreeVerdicts $verdicts, NamedMutators $mutators): array
    {
        $sets = [];

        foreach ($verdicts as $verdict) {
            $package = $verdict->tree()->package();
            $key = $package->path()->value();
            [$held, $mutants] = array_key_exists($key, $sets) ? $sets[$key] : [$package, JudgedMutants::none()];
            $declares = ! $held->securityFloor() instanceof Floor && $package->securityFloor() instanceof Floor;
            $sets[$key] = [$declares ? $package : $held, $mutants->and($verdict->mutants()->madeBy($mutators))];
        }

        return $sets;
    }

    private function secured(Package $package, JudgedMutants $mutants, Entry|Unrecorded $recorded): SecurityVerdict
    {
        $declared = $package->securityFloor();
        $verdict = SecurityVerdict::judged(
            $package,
            $declared instanceof Floor ? $declared : $this->floor,
            $recorded instanceof Entry ? $recorded->floor() : $recorded,
            $mutants,
            $this->uncovered,
        );
        $lowering = $recorded instanceof Entry ? $recorded->lowering() : Unlowered::floor();

        return $lowering instanceof Lowered ? $verdict->withLowering($lowering) : $verdict;
    }
}
