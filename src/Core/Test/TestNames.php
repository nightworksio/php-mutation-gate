<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;
use function count;

use Countable;

use function implode;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Format\Fit;

use function sprintf;

use Traversable;

/**
 * What a runner names each test of its coverage by: the id a coverage map
 * keys a test by, and the test or data set row its JUnit entry names
 * (ADR-0014, decision 6).
 *
 * @implements IteratorAggregate<TestId, TestName|TestRow>
 */
final readonly class TestNames implements Countable, IteratorAggregate
{
    /** @param array<array-key, TestName|TestRow> $names by test id, which PHP keys as a number where it reads as one */
    private function __construct(private array $names)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These names, with this id named so; a later name for the same id replaces the earlier one. */
    public function with(TestId $test, TestName|TestRow $name): self
    {
        $names = $this->names;
        $names[$test->value()] = $name;

        return new self($names);
    }

    /** The name the runner gave this test or row; the id itself where it gave none. */
    public function nameOf(TestId $test): TestName|TestRow|TestId
    {
        return array_key_exists($test->value(), $this->names) ? $this->names[$test->value()] : $test;
    }

    /** The whole test this id is, or is a row of; the id itself where the runner named it nothing. */
    public function testOf(TestId $test): TestName|TestId
    {
        $name = $this->nameOf($test);

        return $name instanceof TestRow ? $name->test() : $name;
    }

    /**
     * Each of these tests by the name its runner gave it, or by its id where
     * it gave none, comma-separated on one plain line: a project's tests
     * choose their names, so a name can start no workflow command and break
     * no line.
     */
    public function listed(TestIds $tests): string
    {
        $named = [];

        foreach ($tests as $test) {
            $named[] = Fit::plain($this->nameOf($test)->value());
        }

        return implode(', ', $named);
    }

    public function count(): int
    {
        return count($this->names);
    }

    /** @return Traversable<TestId, TestName|TestRow> each id the runner named, with its name, in the order named */
    public function getIterator(): Traversable
    {
        foreach ($this->names as $id => $name) {
            yield TestId::of(sprintf('%s', $id)) => $name;
        }
    }
}
