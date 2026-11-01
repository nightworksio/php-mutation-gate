<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

/**
 * The name of a repository within its organisation, as GitHub allows one: `api`, `php-mutation-gate` (ADR-0024).
 *
 * @internal
 */
final readonly class RepositoryName
{
    /** Every name GitHub gives a repository, as a regular expression without its anchors. */
    public const string PATTERN = '[A-Za-z0-9._-]+';
}
