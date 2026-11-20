<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A mutant a shard of the run judged, with the route that brought it to its verdict (ADR-0230).
 *
 * @internal
 */
final readonly class RoutedMutant
{
    private function __construct(private Mutant $mutant, private Route|NotGiven $route)
    {
    }

    public static function of(Mutant $mutant, Route|NotGiven $route): self
    {
        return new self($mutant, $route);
    }

    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    /** The route that brought it to its verdict; none where no route did. */
    public function route(): Route|NotGiven
    {
        return $this->route;
    }
}
