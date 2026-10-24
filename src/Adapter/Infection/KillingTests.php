<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function count;
use function file_get_contents;
use function is_array;
use function is_file;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestAt;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

use function preg_split;
use function sprintf;

/**
 * The tests that killed a mutant, read from what PHPUnit printed for its
 * run, which Infection's JSON log keeps as `processOutput` (see
 * PrintedTests). Where a test failed or met an error, those tests killed it.
 * Where none did, the tests whose issues failed the run did (ADR-0014), as
 * the run's config fails it on them (see FailingIssues): each risky test,
 * each test PHPUnit warned of, each test a warning lists as triggering it,
 * and, for a warning raised in a test itself, the test method its line is in
 * (see TestAt), as each data set of it the coverage map knows. A class a
 * list names alone, as it names one whose `setUpBeforeClass` failed, stands
 * for each test of it the coverage map knows. A run killed with no such
 * list, by a crash or a timeout, names none.
 */
final readonly class KillingTests
{
    /** The lines of what PHPUnit printed. */
    private const string LINES = '/\R/';

    private function __construct(private FailingIssues $failing, private TestIds $known)
    {
    }

    /** The killers of mutants whose runs fail on these issues, among the tests the coverage map knows. */
    public static function of(FailingIssues $failing, TestIds $known): self
    {
        return new self($failing, $known);
    }

    public function in(string $output): TestIds
    {
        $lines = preg_split(self::LINES, $output);
        $read = PrintedTests::none();

        foreach (is_array($lines) ? $lines : [] as $line) {
            $read = $read->read($line, $this->failing);
        }

        $read = $read->ended();

        $defects = $this->named($read->defects());

        if (count($defects) > 0) {
            return $defects;
        }

        $tests = $this->named($read->issued());

        foreach ($read->places() as $place) {
            $tests = $tests->and($this->testsAt($place['file'], Line::of($place['line'])));
        }

        return $tests;
    }

    /** These tests, with a class among them named as each test of it the coverage map knows. */
    private function named(TestIds $printed): TestIds
    {
        $named = TestIds::none();

        foreach ($printed as $test) {
            $named = TestMethod::of($test) instanceof TestMethod
                ? $named->with($test)
                : $named->and($this->testsOf($test->value()));
        }

        return $named;
    }

    /** The tests the coverage map knows of a class. */
    private function testsOf(string $class): TestIds
    {
        $found = TestIds::none();

        foreach ($this->known as $test) {
            $found = TestMethod::classOf($test) === $class ? $found->with($test) : $found;
        }

        return $found;
    }

    /** The tests the coverage map knows of the test method a line of a test file is in, each data set's. */
    private function testsAt(string $file, Line $line): TestIds
    {
        $method = is_file($file)
            ? TestAt::in(Contents::of(sprintf('%s', file_get_contents($file))), $line)
            : Nameless::code();

        if ($method instanceof Nameless) {
            return TestIds::none();
        }

        $found = TestIds::none();

        foreach ($this->known as $test) {
            $found = $this->methodOf($test) === $method->value() ? $found->with($test) : $found;
        }

        return $found;
    }

    /** The id of the test method a test runs, whatever its data set; the id itself where it names no method. */
    private function methodOf(TestId $test): string
    {
        $method = TestMethod::of($test);

        return $method instanceof TestMethod
            ? TestMethod::id($method->className(), $method->method())->value()
            : $method->value();
    }
}
