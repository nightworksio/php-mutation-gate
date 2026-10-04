<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;

use function ksort;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use Traversable;

/**
 * The committed floors: one per tree, keyed by the tree's path from the
 * root, and one per package's security set, keyed by the package's path
 * (ADR-0021, decision 17), each kept in byte order of those paths. It holds
 * floors and nothing else, so it changes only when a floor moves.
 *
 * @implements IteratorAggregate<int, Entry>
 */
final readonly class Baseline implements Countable, IteratorAggregate
{
    /**
     * @param array<string, Entry> $entries  by tree path, in byte order
     * @param array<string, Entry> $security by package path, in byte order
     */
    private function __construct(private array $entries, private array $security)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /** A baseline of these trees' entries, with no security set's. */
    public static function of(Entry ...$entries): self
    {
        return new self(self::keyed([], array_values($entries)), []);
    }

    /** This baseline, with this tree's entry in place of any it had. */
    public function with(Entry $entry): self
    {
        return new self(self::keyed($this->entries, [$entry]), $this->security);
    }

    /** This baseline, with these packages' security entries in place of any it had. */
    public function withSecurity(Entry ...$entries): self
    {
        return new self($this->entries, self::keyed($this->security, array_values($entries)));
    }

    public function entryOf(Path $tree): Entry|Unrecorded
    {
        return array_key_exists($tree->value(), $this->entries) ? $this->entries[$tree->value()] : Unrecorded::floor();
    }

    public function floorOf(Path $tree): Floor|Unrecorded
    {
        $entry = $this->entryOf($tree);

        return $entry instanceof Entry ? $entry->floor() : $entry;
    }

    /** The entry of a package's security set. */
    public function securityOf(Path $package): Entry|Unrecorded
    {
        return array_key_exists($package->value(), $this->security)
            ? $this->security[$package->value()]
            : Unrecorded::floor();
    }

    /**
     * Each package's security entry, in byte order of their paths.
     *
     * @return list<Entry>
     */
    public function security(): array
    {
        return array_values($this->security);
    }

    /**
     * This baseline with every floor a verdict raised set to the score it
     * measured, and its `lowered` dropped. Nothing is ever lowered here.
     */
    public function raisedBy(TreeVerdicts $trees, SecurityVerdicts $security): self
    {
        $raised = [];
        $secured = [];

        foreach ($trees as $verdict) {
            $floor = $verdict->raised();
            $raised = $floor instanceof Floor ? [...$raised, Entry::of($verdict->tree()->path(), $floor)] : $raised;
        }

        foreach ($security as $verdict) {
            $floor = $verdict->raised();
            $secured = $floor instanceof Floor
                ? [...$secured, Entry::of($verdict->package()->path(), $floor)]
                : $secured;
        }

        return new self(self::keyed($this->entries, $raised), self::keyed($this->security, $secured));
    }

    /** How many trees it holds a floor for. */
    public function count(): int
    {
        return count($this->entries);
    }

    /** @return Traversable<int, Entry> each tree's entry, in byte order of their paths */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->entries));
    }

    /**
     * @param  array<string, Entry> $entries
     * @param  list<Entry>          $more
     * @return array<string, Entry> both, by path in byte order, the later entry of a path in place of the earlier
     */
    private static function keyed(array $entries, array $more): array
    {
        foreach ($more as $entry) {
            $entries[$entry->path()->value()] = $entry;
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }
}
