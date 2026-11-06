<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use function array_filter;
use function array_map;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumers;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

/**
 * The mutants a native run judges, in the order it starts them: each another
 * of them subsumes after the others, where the run holds subsumed mutants
 * back (ADR-0079), each part in the order the engine made them.
 *
 * @internal
 */
final readonly class HeldLast
{
    /**
     * @param  list<MadeMutant> $made
     * @return list<MadeMutant>
     */
    public static function of(array $made, Subsumption|NotGiven $held): array
    {
        if (! $held instanceof Subsumption) {
            return $made;
        }

        $subsumers = Subsumers::among($held, ...array_map(PreChecked::unrun(...), $made));
        $isHeld = static fn(MadeMutant $mutant): bool => $subsumers->holds($mutant->id());

        return [
            ...array_filter($made, static fn(MadeMutant $mutant): bool => ! $isHeld($mutant)),
            ...array_filter($made, $isHeld),
        ];
    }
}
