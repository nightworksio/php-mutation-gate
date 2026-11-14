<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

/**
 * A method of the artifact service the gate calls (ADR-0134).
 *
 * @internal
 */
enum ArtifactMethod: string
{
    case ListArtifacts = 'ListArtifacts';
    case CreateArtifact = 'CreateArtifact';
    case FinalizeArtifact = 'FinalizeArtifact';
}
