<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use NightWorksIO\MutationGate\Core\Test\Group;

use function sprintf;

/**
 * One declaration that some tests hold a path: a group the runner lists as
 * `holds:<path>`, or a `#[Holds]` on a test class or method.
 */
final readonly class Holding
{
    private function __construct(private string $declared, private Group|Holder $by)
    {
    }

    /** A group whose name is `holds:` followed by the path. */
    public static function byGroup(string $declared, Group $group): self
    {
        return new self($declared, $group);
    }

    /** A `#[Holds]` on a test class, or on a method written as `Class::method`. */
    public static function byAttribute(string $declared, Holder $holder): self
    {
        return new self($declared, $holder);
    }

    /** The path as the declaration spells it. */
    public function declared(): string
    {
        return $this->declared;
    }

    /** The group that holds it, or the class or method `#[Holds]` stands on. */
    public function by(): Group|Holder
    {
        return $this->by;
    }

    /** The declaration as its author wrote it, to name it in a message. */
    public function written(): string
    {
        return $this->by instanceof Group
            ? $this->by->name()
            : sprintf("#[Holds('%s')] on %s", $this->declared, $this->by->written());
    }
}
