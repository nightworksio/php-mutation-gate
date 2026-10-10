<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function count;

use Countable;

use function iterator_to_array;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use Traversable;

/**
 * The executable lines of one file of a coverage map: each line some test
 * ran, by number in ascending order, with the set of tests that ran it, named
 * alike for the same set anywhere in the map ({@see TestSet}); and the lines
 * no test ran.
 *
 * @implements IteratorAggregate<int, TestSet>
 */
final readonly class LineSets implements Countable, IteratorAggregate
{
    /** @param array<int, TestSet> $covered each covered line's set, by number, ascending */
    private function __construct(private LineTests $map, private array $covered, private Lines $missed)
    {
    }

    /** @param Traversable<int, TestSet> $covered each covered line's set, by number, ascending */
    public static function of(LineTests $map, Traversable $covered, Lines $missed): self
    {
        return new self($map, iterator_to_array($covered, preserve_keys: true), $missed);
    }

    /** The executable lines no test ran. */
    public function missed(): Lines
    {
        return $this->missed;
    }

    /** The tests of a set these lines name, each once, in byte order of their ids. */
    public function testsOf(TestSet $set): TestIds
    {
        return $this->map->testsOfSet($set);
    }

    /** How many lines some test ran. */
    public function count(): int
    {
        return count($this->covered);
    }

    /** @return Traversable<int, TestSet> each covered line's set, by number, ascending */
    public function getIterator(): Traversable
    {
        yield from $this->covered;
    }
}
