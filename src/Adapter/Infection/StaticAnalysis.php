<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

/**
 * Who runs static analysis over Infection's mutants (ADR-0020, decision 13):
 * Infection, with the tool the project's config names, or the gate, which
 * checks the survivors itself where `staticCheck.tool` chooses an analyser.
 * The config the gate writes keeps the project's static analysis keys only
 * where Infection runs it.
 */
enum StaticAnalysis: string
{
    case Infection = 'infection';

    case Gate = 'gate';
}
