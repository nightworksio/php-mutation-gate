<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

/** A GitHub repository topic, such as `mutation-gate`: lowercase letters, digits and hyphens, at most 50 (ADR-0024). */
final readonly class Topic
{
    /** Every topic GitHub allows, as a regular expression without its anchors. */
    public const string PATTERN = '[a-z0-9][a-z0-9-]{0,49}';
}
