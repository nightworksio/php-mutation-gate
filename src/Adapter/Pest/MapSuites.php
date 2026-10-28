<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;

/** A map's tests, counted once (see Suite), kept for every question about the map asked about last. */
final class MapSuites
{
    /** @var array{CoverageMap, Suite}|array{} the last map asked about, and its tests, counted */
    private array $read = [];

    public function of(CoverageMap $map): Suite
    {
        if ($this->read === [] || $this->read[0] !== $map) {
            $this->read = [$map, Suite::of($map->tests())];
        }

        return $this->read[1];
    }
}
