<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_map;

use NightWorksIO\MutationGate\Attribute\Holds;
use NightWorksIO\MutationGate\Core\Native\Candidate;
use NightWorksIO\MutationGate\Core\Test\Group;
use PhpSpec\Loader\Node\ExampleNode;
use ReflectionAttribute;

use function sprintf;

/**
 * One example of a spec, as the gate names it: its spec's class and its
 * method, as `Class::method`, the id a test of any runner has, the file its
 * spec is declared in, and the holding groups the `#[Holds]` on its spec or
 * on its method put it in.
 *
 * @internal
 */
final readonly class Example
{
    public static function of(ExampleNode $example): Candidate
    {
        $class = $example->getSpecification()->getClassReflection();
        $method = $example->getFunctionReflection();
        $holds = [...$class->getAttributes(Holds::class), ...$method->getAttributes(Holds::class)];

        return Candidate::of(
            sprintf('%s::%s', $class->getName(), $method->getName()),
            (string) $class->getFileName(),
            ...array_map(
                static fn(ReflectionAttribute $held): Group => Group::holding($held->newInstance()->path()),
                $holds,
            ),
        );
    }
}
