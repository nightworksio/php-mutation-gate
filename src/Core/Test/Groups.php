<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A suite's groups, each once, in the order the runner lists them.
 *
 * @implements IteratorAggregate<int, Group>
 */
final readonly class Groups implements Countable, IteratorAggregate
{
    /** @param array<string, Group> $groups by name */
    private function __construct(private array $groups) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Group ...$groups): self
    {
        $collected = self::none();

        foreach ($groups as $group) {
            $collected = $collected->with($group);
        }

        return $collected;
    }

    public function with(Group $group): self
    {
        $groups = $this->groups;
        $groups[$group->name()] = $group;

        return new self($groups);
    }

    public function has(Group $group): bool
    {
        return array_key_exists($group->name(), $this->groups);
    }

    public function count(): int
    {
        return count($this->groups);
    }

    /** @return Traversable<int, Group> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->groups));
    }
}
