<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

/** What the tests of Infection's process shell set in the environment. */
final readonly class InfectionShells
{
    /** Variables a test sets in the environment the gate runs in. */
    public const array PROBES = ['INFECTION_PROBE', 'MUTATION_GATE_PROBE', 'AWS_SECRET_ACCESS_KEY', 'GITHUB_TOKEN', 'ACTIONS_RUNTIME_TOKEN', 'KEPT_PROBE'];
}
