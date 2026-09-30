<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;

use function ksort;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use Traversable;

/**
 * The committed floors, one per tree, keyed by the tree's path from the root
 * and kept in byte order of those paths. It holds floors and nothing else, so
 * it changes only when a floor moves.
 *
 * @implements IteratorAggregate<int, Entry>
 */
final readonly class Baseline implements Countable, IteratorAggregate
{
    /** @param array<string, Entry> $entries by tree path, in byte order */
    private function __construct(private array $entries)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Entry ...$entries): self
    {
        $collected = [];

        foreach ($entries as $entry) {
            $collected[$entry->tree()->value()] = $entry;
        }

        ksort($collected, SORT_STRING);

        return new self($collected);
    }

    /** This baseline, with this tree's entry in place of any it had. */
    public function with(Entry $entry): self
    {
        $entries = $this->entries;
        $entries[$entry->tree()->value()] = $entry;
        ksort($entries, SORT_STRING);

        return new self($entries);
    }

    public function entryOf(Path $tree): Entry|Unrecorded
    {
        foreach ($this->entries as $entry) {
            if ($entry->tree()->equals($tree)) {
                return $entry;
            }
        }

        return Unrecorded::floor();
    }

    public function floorOf(Path $tree): Floor|Unrecorded
    {
        $entry = $this->entryOf($tree);

        return $entry instanceof Entry ? $entry->floor() : $entry;
    }

    /**
     * This baseline with every floor a verdict raised set to the score it
     * measured, and its `lowered` dropped. Nothing is ever lowered here.
     */
    public function raisedBy(TreeVerdicts $verdicts): self
    {
        $raised = [];

        foreach ($verdicts as $verdict) {
            $floor = $verdict->raised();

            if ($floor instanceof Floor) {
                $raised[] = Entry::of($verdict->tree()->path(), $floor);
            }
        }

        return self::of(...array_values($this->entries), ...$raised);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return Traversable<int, Entry> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->entries));
    }
}
