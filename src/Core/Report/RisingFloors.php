<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The floors a verdict's scores raise, each by what it is of: a tree by its
 * path, and a security set by its package's (ADR-0021, decision 17), each
 * path one plain line, with no control character.
 */
final readonly class RisingFloors
{
    /** @return list<array{string, Floor}> each floor that can rise, by what it is of, trees first */
    public static function of(Verdict $verdict): array
    {
        $rising = [];

        foreach ($verdict->trees() as $tree) {
            $raised = $tree->raised();
            $named = Fit::plain($tree->tree()->path()->value());
            $rising = $raised instanceof Floor ? [...$rising, [$named, $raised]] : $rising;
        }

        foreach ($verdict->sets()->security() as $set) {
            $raised = $set->raised();
            $named = sprintf(SecurityVerdict::SET, Fit::plain($set->package()->path()->value()));
            $rising = $raised instanceof Floor ? [...$rising, [$named, $raised]] : $rising;
        }

        return $rising;
    }
}
