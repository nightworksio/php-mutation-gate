<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_merge;
use function array_unique;
use function array_values;

use Closure;
use NightWorksIO\MutationGate\Core\Test\RunIssues;
use PHPUnit\TestRunner\TestResult\Facade;
use PHPUnit\TestRunner\TestResult\TestResult;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\ShellExitCodeCalculator;

/**
 * The tests a mutant's own run names as its killers where it failed with no
 * test failing or erroring (ADR-0014): each test that raised an
 * issue of a kind its configuration fails the run on (see IssueKind), as
 * PHPUnit's own result counts it. A run that passed, or one a test failed
 * or errored in, whose killers are named as they fail, names none here; so
 * does an issue raised outside any test.
 *
 * @internal
 */
final readonly class IssueKillers implements RunIssues
{
    /**
     * @param Closure(): Configuration $configuration
     * @param Closure(): TestResult    $result
     */
    private function __construct(private Closure $configuration, private Closure $result)
    {
    }

    /** The killers of the run this process runs, read from PHPUnit's registry and result once its tests have run. */
    public static function ofThisRun(): self
    {
        return self::reading(Registry::get(...), Facade::result(...));
    }

    /**
     * The killers of a run whose configuration and result these read once its tests have run.
     *
     * @param Closure(): Configuration $configuration
     * @param Closure(): TestResult    $result
     */
    public static function reading(Closure $configuration, Closure $result): self
    {
        return new self($configuration, $result);
    }

    public function killers(): array
    {
        return self::of(($this->configuration)(), ($this->result)());
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
