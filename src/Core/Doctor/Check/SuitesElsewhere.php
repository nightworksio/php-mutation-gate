<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function implode;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * The runner's suites that serve the application from another process,
 * such as Codeception's with PhpBrowser or WebDriver: a mutant cannot be
 * served there, so the gate runs none of their tests (ADR-0027).
 */
final readonly class SuitesElsewhere
{
    private const string FOUND
        = 'The gate runs no test of the suites %s, which serve the application from another process.';

    private const string WHY
        = 'A mutant runs in the test\'s own process; a test that drives the application elsewhere judges the original.';

    private const string FIX
        = 'Cover what only these suites run with tests in a suite that runs in-process, or leave it out of the trees.';

    public static function in(Observations $observed): Findings
    {
        $suites = $observed->files()->runnerConfig()->suitesElsewhere();

        return $suites === []
            ? Findings::none()
            : Findings::of(Finding::of(
                Slug::SuiteServedElsewhere,
                Severity::Advice,
                sprintf(self::FOUND, implode(', ', $suites)),
                self::WHY,
                self::FIX,
            ));
    }
}
