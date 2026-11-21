<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * Mutants by the units holding them, each unit's in their order: those of
 * its file, or of the files inside its held path. One pass over the mutants
 * groups them for every unit.
 *
 * @internal
 */
final readonly class MutantsInside
{
    /** @param array<string, list<Mutant>> $inside by each unit's path */
    private function __construct(private array $inside)
    {
    }

    /** These mutants inside each of these units. */
    public static function of(Mutants $mutants, Path ...$units): self
    {
        $inside = [];

        foreach ($units as $unit) {
            $inside[$unit->value()] = [];
        }

        foreach ($mutants as $mutant) {
            foreach ($mutant->location()->file()->enclosing() as $unit) {
                if (array_key_exists($unit->value(), $inside)) {
                    $inside[$unit->value()][] = $mutant;
                }
            }
        }

        return new self($inside);
    }

    /** The mutants inside this unit; none where it is not one of the units. */
    public function in(Path $unit): Mutants
    {
        return Mutants::of(...array_key_exists($unit->value(), $this->inside) ? $this->inside[$unit->value()] : []);
    }
}
