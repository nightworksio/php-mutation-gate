<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\HeldLast;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;
use NightWorksIO\MutationGate\Tests\Support\Subsumed;

/**
 * The ids of these made mutants, in their order.
 *
 * @param  list<MadeMutant> $made
 * @return list<string>
 */
function heldLastOrder(array $made): array
{
    return array_map(static fn(MadeMutant $mutant): string => $mutant->id()->value(), $made);
}

/** A mutant the engine made of line 1 of `src/Money.php`, changing it as this diff says. */
function heldLastMade(string $diff, int $line = 1): MadeMutant
{
    $mutant = Subsumed::mutant('src/Money.php', $line, $diff);

    return MadeMutant::of($mutant->id(), $mutant->location(), $mutant->mutation(), Contents::of('<?php'));
}

it('starts each mutant another subsumes after the others, each part in the order the engine made them, where the run holds them back', function (): void {
    $negated = heldLastMade(Subsumed::diff('return $a < $b;', 'return $a >= $b;'));
    $loosened = heldLastMade(Subsumed::diff('return $a < $b;', 'return $a <= $b;'));
    $other = heldLastMade(Subsumed::diff('return $c;', 'return null;'), 2);

    expect(heldLastOrder(HeldLast::of([$negated, $loosened, $other], Subsumption::none())))
        ->toBe(heldLastOrder([$loosened, $other, $negated]))
        ->and(heldLastOrder(HeldLast::of([$negated, $loosened, $other], NotGiven::value())))
        ->toBe(heldLastOrder([$negated, $loosened, $other]));
});
