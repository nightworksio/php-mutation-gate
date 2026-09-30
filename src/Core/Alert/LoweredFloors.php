<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use Traversable;

/**
 * The trees whose floor went down since the newest trend entry, in the
 * order the verdict judged them.
 *
 * @implements IteratorAggregate<int, LoweredFloor>
 */
final readonly class LoweredFloors implements Countable, IteratorAggregate
{
    /** @param list<LoweredFloor> $floors */
    private function __construct(private array $floors)
    {
    }

    /** Each tree of this verdict held to a floor below the one this entry recorded for it. */
    public static function between(Verdict $verdict, TrendEntry $previous): self
    {
        $floors = [];

        foreach ($verdict->trees() as $tree) {
            $path = $tree->tree()->path();
            $now = $tree->floor();
            $before = $previous->floorOf($path);
            $lowered = $now instanceof Floor && $before instanceof Floor && $now->hundredths() < $before->hundredths();
            $floors = $lowered ? [...$floors, LoweredFloor::of($path, $before, $now, $tree->lowering())] : $floors;
        }

        return new self($floors);
    }

    public function count(): int
    {
        return count($this->floors);
    }

    /** @return Traversable<int, LoweredFloor> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->floors);
    }
}
