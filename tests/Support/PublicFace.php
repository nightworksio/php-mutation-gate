<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function class_exists;
use function enum_exists;
use function interface_exists;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * What a class shows the code that uses it: its parents and interfaces, and
 * every type its public methods and properties write, natively or in their
 * doc blocks (ADR-0043).
 */
final readonly class PublicFace
{
    /** @param ReflectionClass<object> $class */
    private function __construct(private ReflectionClass $class)
    {
    }

    /** @param ReflectionClass<object> $class */
    public static function of(ReflectionClass $class): self
    {
        return new self($class);
    }

    /**
     * The core types the face names.
     *
     * @return list<class-string>
     */
    public function coreTypes(): array
    {
        return array_values(array_filter(
            [...$this->ancestors(), ...$this->methods(), ...$this->properties()],
            static fn(string $name): bool => Layer::Core->holds($name) && (class_exists($name) || interface_exists($name) || enum_exists($name)),
        ));
    }

    /** @return list<string> */
    private function ancestors(): array
    {
        $parent = $this->class->getParentClass();

        return [...($parent instanceof ReflectionClass ? [$parent->getName()] : []), ...$this->class->getInterfaceNames()];
    }

    /** @return list<string> */
    private function methods(): array
    {
        return array_merge([], ...array_map(
            fn(ReflectionMethod $method): array => [
                ...Api::returnNames($method),
                ...DocTypes::in($method->getDocComment(), $this->class),
                ...array_merge([], ...array_map(Api::parameterNames(...), $method->getParameters())),
            ],
            Api::publicMethodsOf($this->class),
        ));
    }

    /** @return list<string> */
    private function properties(): array
    {
        $own = array_filter(
            $this->class->getProperties(ReflectionProperty::IS_PUBLIC),
            fn(ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $this->class->getName(),
        );

        return array_merge([], ...array_map(
            fn(ReflectionProperty $property): array => [
                ...Api::propertyNames($property),
                ...DocTypes::in($property->getDocComment(), $this->class),
            ],
            array_values($own),
        ));
    }
}
