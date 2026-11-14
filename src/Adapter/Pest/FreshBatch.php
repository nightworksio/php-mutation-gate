<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;

/**
 * What every fresh run of one request shares (ADR-0148): the request, the
 * coverage its covering tests are read from, the suite they are selected
 * from, the bounds of their limit, the gate's override and the memory scan
 * each run goes under.
 *
 * @internal
 */
final readonly class FreshBatch
{
    public function __construct(
        public MutationRequest $request,
        public Covering $coverage,
        public CoverageMap $map,
        public Suite $suite,
        public LimitBounds $bounds,
        public string $override,
        public MemoryScan $scan,
    ) {
    }
}
