<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function array_any;
use function array_values;

use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * A test as a framework's extension finds it, about to run: its id, the
 * file it is declared in, by its path on disk, and the groups it is in.
 */
final readonly class Candidate
{
    /**
     * @param non-empty-string $id
     * @param list<Group>      $groups
     */
    private function __construct(private string $id, private string $file, private array $groups)
    {
    }

    /**
     * A test of this id, declared in this file, in these groups.
     *
     * @param non-empty-string $id
     */
    public static function of(string $id, string $file, Group ...$groups): self
    {
        return new self($id, $file, array_values($groups));
    }

    public function id(): TestId
    {
        return TestId::of($this->id);
    }

    /**
     * Its id as a coverage map keys the lines it ran.
     *
     * @return non-empty-string
     */
    public function key(): string
    {
        return $this->id;
    }

    /** The file it is declared in, by its path on disk. */
    public function file(): string
    {
        return $this->file;
    }

    /** @return list<Group> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** Whether it is in the group of this name. */
    public function isIn(Group $group): bool
    {
        return array_any($this->groups, static fn(Group $each): bool => $each->name() === $group->name());
    }
}
