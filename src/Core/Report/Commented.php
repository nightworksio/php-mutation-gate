<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/**
 * How many entries a list of the pull request comment shows before it says
 * how many more there are (ADR-0009, decision 3), and so how many survivors a
 * pull request's run re-checks first by default (ADR-0020, decision 19).
 */
final readonly class Commented
{
    public const int MOST = 20;
}
