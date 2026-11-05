<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** `runner.pathRules`: how a shard applies what `paths` sets for the files it matches (ADR-0074). */
enum PathRuleMode: string
{
    /**
     * A shard groups its units by what the entries matching them set: the units no entry matches run as they
     * would without `paths`, and each other group runs on its own, with a runner built from its settings.
     */
    case Partitioned = 'partitioned';
}
