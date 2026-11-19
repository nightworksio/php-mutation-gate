<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * Where a run's minutes went (ADR-0230): what each route cost, beside the
 * seconds a fork alone takes (ADR-0091); the shards' seconds in no route; its
 * costliest mutants and tests; its wall and runner time, its jobs, its
 * rounds and the mutants it left unjudged; and its core-seconds against one
 * run of the test suite, as the coverage map timed it.
 */
final readonly class CostReport
{
    /** The seconds a fork alone takes, as ADR-0091 measured it: the least a mutant's verdict can cost. */
    public const float FORK_FLOOR = 0.03;

    private function __construct(
        private RouteCosts $routes,
        private Seconds $outside,
        private CostlyMutants $mutants,
        private CostlyTests $tests,
        private RunTime $spent,
        private RunShape $shape,
        private Seconds $coreSeconds,
        private Seconds $suite,
    ) {
    }

    /**
     * The report of a run whose routes cost these, whose shards spent this
     * outside them, with these costliest mutants and tests, that took this
     * long in this shape, these core-seconds, against a suite of this long.
     */
    public static function of(
        RouteCosts $routes,
        Seconds $outside,
        CostlyMutants $mutants,
        CostlyTests $tests,
        RunTime $spent,
        RunShape $shape,
        Seconds $coreSeconds,
        Seconds $suite,
    ): self {
        return new self($routes, $outside, $mutants, $tests, $spent, $shape, $coreSeconds, $suite);
    }

    /** Each route, in the order a mutant may meet them. */
    public function routes(): RouteCosts
    {
        return $this->routes;
    }

    /** The seconds the shards spent in no route: starting up, coverage, trials, controls and reading. */
    public function outside(): Seconds
    {
        return $this->outside;
    }

    /** The costliest mutants the run judged, the costliest first. */
    public function mutants(): CostlyMutants
    {
        return $this->mutants;
    }

    /** The tests that can have cost the run most, the costliest first. */
    public function tests(): CostlyTests
    {
        return $this->tests;
    }

    /** The run's wall and runner time. */
    public function spent(): RunTime
    {
        return $this->spent;
    }

    /** How many jobs the run took: its plan where it was timed, its shards, a second wave's and its verdict. */
    public function jobs(): int
    {
        return $this->shape->jobs();
    }

    /** How many rounds of shards the run took: none, one, or two where a second wave judged what the first left. */
    public function rounds(): int
    {
        return $this->shape->rounds();
    }

    /** How many mutants the verdict left unjudged. */
    public function unjudged(): int
    {
        return $this->shape->unjudged();
    }

    /** The run's runner seconds, each job's times the cores of its machine. */
    public function coreSeconds(): Seconds
    {
        return $this->coreSeconds;
    }

    /** One run of the test suite, test after test, as the coverage map timed it. */
    public function suite(): Seconds
    {
        return $this->suite;
    }

    /** How many runs of the test suite the run cost, in core-seconds; none where the map timed no test. */
    public function multiple(): float|NotGiven
    {
        return $this->suite->seconds() > 0.0
            ? $this->coreSeconds->seconds() / $this->suite->seconds()
            : NotGiven::value();
    }
}
