<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use Traversable;

/**
 * A whole test, with its data set rows folded in: useless only when every
 * row is (ADR-0014, decision 6). A test with no data set is its own one row.
 *
 * @implements IteratorAggregate<int, TestStanding>
 */
final readonly class WholeTest implements Countable, IteratorAggregate
{
    /** @param list<TestStanding> $rows */
    private function __construct(private TestName|TestId $test, private array $rows)
    {
    }

    public static function of(TestName|TestId $test, TestStanding ...$rows): self
    {
        return new self($test, array_values($rows));
    }

    /** This test, with one more of its rows. */
    public function with(TestStanding $row): self
    {
        return new self($this->test, [...$this->rows, $row]);
    }

    public function test(): TestName|TestId
    {
        return $this->test;
    }

    /** What its rows come to together. */
    public function standing(): Standing
    {
        $standing = Standing::NotAssessed;

        foreach ($this->rows as $row) {
            $standing = $standing->and($row->standing());
        }

        return $standing;
    }

    /** How many mutants its rows judged with a known result. */
    public function judged(): int
    {
        $judged = 0;

        foreach ($this->rows as $row) {
            $judged += $row->judged();
        }

        return $judged;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** @return Traversable<int, TestStanding> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }
}
