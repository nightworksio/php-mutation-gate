<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * The run of no test that times what a mutant's run spends before its
 * first test (see Runner::startUp()), as a runner keeps its files.
 */
final readonly class StartUp
{
    /** The directory of its files, among a runner's own. */
    public const string DIRECTORY = 'start-up';
}
