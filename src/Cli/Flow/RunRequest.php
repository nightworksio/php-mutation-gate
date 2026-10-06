<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * A mutation request as every run makes one, and every reproduction of a
 * run's mutant (ADR-0004, decisions 6 and 9): these files by these tests,
 * with the mutators the run is narrowed to (ADR-0021, decision 20),
 * withholding what the run withholds, under the memory cap the config sets,
 * each mutant started as `runner.workers` says, one at a time.
 * What decides a mutant's outcome is set here once, so a run and its
 * reproduction cannot differ in it.
 */
final readonly class RunRequest
{
    public static function of(
        Adapters $adapters,
        Settings $settings,
        Paths $files,
        WholeSuite|Group|Filter $judgedBy,
    ): MutationRequest {
        return MutationRequest::of($files, $judgedBy)
            ->narrowedTo($files, $adapters->narrowing)
            ->withholding($adapters->withheld)
            ->cappedAt($settings->runner()->memory())
            ->across(Pool::of(ProcessCount::single(), $settings->runner()->workers()));
    }
}
