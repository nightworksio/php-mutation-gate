<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `tests.order`: the order a mutant's covering tests run in (ADR-0013). */
enum TestOrder: string
{
    /** The tests that killed it before first, most often first, then the rest fastest first. */
    case KillersFirst = 'killers-first';

    /** The runner's own order. */
    case Runner = 'runner';
}
