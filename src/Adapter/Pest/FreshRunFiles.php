<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Native\PlacedMutant;

/**
 * The files of a mutant's fresh run, in its own directory (ADR-0148): the
 * mutant placed, the JUnit log its run writes, the unmutated copy its
 * control serves and what the wrapper of its control writes it served.
 *
 * @internal
 */
final readonly class FreshRunFiles
{
    public function __construct(
        public PlacedMutant $placed,
        public string $log,
        public string $unmutated,
        public string $controlGuard,
    ) {
    }
}
