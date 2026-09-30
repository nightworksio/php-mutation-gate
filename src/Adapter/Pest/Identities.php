<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

/**
 * The gate's id of each mutant Pest planned, the one place it is made: its
 * file as the project spells it, its mutator and its change, and how many
 * mutants before it, by file and then by line, share all three.
 */
final readonly class Identities
{
    /**
     * @param  list<PlannedMutant> $planned in the order PlannedMutant::inOrder puts them
     * @return list<MutantId>      each mutant's id, in the order given
     */
    public static function of(Root $root, array $planned): array
    {
        $ids = [];
        $seen = [];

        foreach ($planned as $mutant) {
            $path = $root->relative($mutant->file()->value());
            $diff = Diff::fromPest($mutant->diff());
            $key = MutantId::hash($path, $mutant->mutator(), $diff, 0)->value();
            $occurrence = array_key_exists($key, $seen) ? $seen[$key] : 0;
            $ids[] = MutantId::hash($path, $mutant->mutator(), $diff, $occurrence);
            $seen[$key] = $occurrence + 1;
        }

        return $ids;
    }
}
