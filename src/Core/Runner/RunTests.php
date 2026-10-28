<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * The tests a mutant's own run runs, and how it selected them (ADR-0008): by
 * a filter the runner built, by the test files it loaded with no filter, or
 * every one, or as the runner selects any set of tests. A control that
 * reproduces the run selects them the same way.
 */
final readonly class RunTests
{
    private function __construct(private TestIds $tests, private Filter|Paths|WholeSuite|NotGiven $by)
    {
    }

    /** These tests, as the runner selects any set of tests. */
    public static function of(TestIds $tests): self
    {
        return new self($tests, NotGiven::value());
    }

    /** These tests, selected by this filter. */
    public static function filtered(TestIds $tests, Filter $filter): self
    {
        return new self($tests, $filter);
    }

    /** These tests, every one of the test files the run loaded with no filter, or of every test file. */
    public static function loading(TestIds $tests, Paths|WholeSuite $files): self
    {
        return new self($tests, $files);
    }

    public function tests(): TestIds
    {
        return $this->tests;
    }

    /** How the run selected them: a filter, the test files it loaded, every one, or not given. */
    public function by(): Filter|Paths|WholeSuite|NotGiven
    {
        return $this->by;
    }
}
