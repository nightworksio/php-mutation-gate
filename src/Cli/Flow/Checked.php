<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * What static analysis's checks of a shard's survivors came to: its mutants,
 * each survivor the analyser rejected killed by static analysis, the checks'
 * time and the survivors they left unchecked, the survivors they took up, as
 * they were before it, and those a check that never ran may yet kill.
 *
 * @internal
 */
final readonly class Checked
{
    public function __construct(
        public Mutants $mutants,
        public SurvivorChecks $checks,
        public Mutants $examined,
        public MutantIds $unsettled,
    ) {
    }

    /** These mutants, with every survivor taken up and left unchecked for the same reason. */
    public static function leaving(Mutants $mutants, Mutants $survivors, Unchecked $why): self
    {
        $checks = SurvivorChecks::none();

        foreach ($survivors as $survivor) {
            $checks = $checks->leaving(UncheckedSurvivor::of($why, $survivor->location()->file()));
        }

        return new self($mutants, $checks, $survivors, $why->settles() ? MutantIds::none() : self::idsOf($survivors));
    }

    /** The ids of the survivors the checks took up, killed, passed or left. */
    public function examinedIds(): MutantIds
    {
        return self::idsOf($this->examined);
    }

    private static function idsOf(Mutants $mutants): MutantIds
    {
        return MutantIds::of(...array_map(static fn(Mutant $mutant): MutantId => $mutant->id(), [...$mutants]));
    }
}
