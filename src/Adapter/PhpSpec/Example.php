<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_any;
use function array_map;

use NightWorksIO\MutationGate\Attribute\Holds;
use NightWorksIO\MutationGate\Core\Test\Group;
use PhpSpec\Loader\Node\ExampleNode;
use ReflectionAttribute;

use function sprintf;

/**
 * One example of a spec, as the gate names it: its spec's class and its
 * method, as `Class::method`, the id a test of any runner has, and the
 * holding groups the `#[Holds]` on its spec or on its method put it in.
 */
final readonly class Example
{
    /**
     * @param non-empty-string $id
     * @param list<Group>      $groups
     */
    private function __construct(private string $id, private string $file, private array $groups)
    {
    }

    public static function of(ExampleNode $example): self
    {
        $class = $example->getSpecification()->getClassReflection();
        $method = $example->getFunctionReflection();
        $holds = [...$class->getAttributes(Holds::class), ...$method->getAttributes(Holds::class)];

        return new self(
            sprintf('%s::%s', $class->getName(), $method->getName()),
            (string) $class->getFileName(),
            array_map(
                static fn(ReflectionAttribute $held): Group => Group::holding($held->newInstance()->path()),
                $holds,
            ),
        );
    }

    /** @return non-empty-string */
    public function id(): string
    {
        return $this->id;
    }

    /** The file its spec is declared in, on disk. */
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
        return array_any($this->groups, fn(Group $each): bool => $each->name() === $group->name());
    }
}
