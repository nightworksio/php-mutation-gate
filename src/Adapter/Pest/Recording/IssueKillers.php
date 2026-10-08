<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_merge;
use function array_unique;
use function array_values;

use PHPUnit\TestRunner\TestResult\Facade;
use PHPUnit\TestRunner\TestResult\TestResult;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\ShellExitCodeCalculator;

/**
 * The tests a mutant's own run names as its killers where it failed with no
 * test failing or erroring (ADR-0014, decision 17): each test that raised an
 * issue of a kind its configuration fails the run on (see IssueKind), as
 * PHPUnit's own result counts it. A run that passed, or one a test failed
 * or errored in, whose killers are named as they fail, names none here; so
 * does an issue raised outside any test.
 */
final readonly class IssueKillers implements RunIssues
{
    /** The tests this process's run names so, as PHPUnit's registry and result hold them once its tests have run. */
    public function killers(): array
    {
        return self::of(Registry::get(), Facade::result());
    }

    /** @return list<string> the tests, by id, each once */
    public static function of(Configuration $configuration, TestResult $result): array
    {
        if (! $result->wasSuccessful() || new ShellExitCodeCalculator()->calculate($configuration, $result) === 0) {
            return [];
        }

        $tests = [];

        foreach (IssueKind::cases() as $kind) {
            $tests[] = $kind->fails($configuration) ? $kind->testsIn($result) : [];
        }

        return array_values(array_unique(array_merge([], ...$tests)));
    }
}
