<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_pop;
use function array_udiff;
use function array_values;
use function class_exists;
use function enum_exists;
use function in_array;
use function interface_exists;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

use function sprintf;
use function trait_exists;

/**
 * The classes under `src`, loaded, and the API surface among them: every class
 * in Attribute, Port, Mutator, Config and Extension but their machinery, and
 * every core type their public faces reach, followed as far as it goes
 * (ADR-0001, ADR-0043).
 */
final readonly class Api
{
    /**
     * The machinery inside the public layers, which no public signature reaches: the mutator engine, and the
     * checks the contract kits make.
     */
    public const array MACHINERY = ['src/Mutator/Engine', 'src/Extension/Checks'];

    /**
     * Every class-like of the code the package ships, src's and each
     * plugin's, loaded.
     *
     * @return list<ReflectionClass<object>>
     */
    public static function shippedClasses(): array
    {
        return array_merge(...array_map(self::classesUnder(...), Tree::shipped()));
    }

    /**
     * Every class-like PSR-4 places under a directory, loaded. A file
     * whose path names a class it does not declare is left out here, never
     * loaded, and refused by W1: the autoloader includes such a file again on
     * every lookup, and the second include redeclares what the first declared.
     *
     * @return list<ReflectionClass<object>>
     */
    public static function classesUnder(string $directory): array
    {
        $found = [];

        foreach (Tree::filesUnder($directory) as $path) {
            $class = Source::classAtPath($path);

            if (Source::at($path)->declares() !== [$class]) {
                continue;
            }

            if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                $found[] = new ReflectionClass($class);
            }
        }

        return $found;
    }

    /** @return list<ReflectionClass<object>> */
    public static function surface(): array
    {
        $surface = [];
        $machinery = array_merge(...array_map(self::classesUnder(...), self::MACHINERY));
        $pending = array_udiff(
            [
                ...self::classesUnder(Layer::Attribute->directory()),
                ...self::classesUnder(Layer::Port->directory()),
                ...self::classesUnder(Layer::Mutator->directory()),
                ...self::classesUnder(Layer::Config->directory()),
                ...self::classesUnder(Layer::Extension->directory()),
            ],
            $machinery,
            static fn(ReflectionClass $one, ReflectionClass $other): int => $one->getName() <=> $other->getName(),
        );

        while ($pending !== []) {
            $class = array_pop($pending);

            if (array_key_exists($class->getName(), $surface)) {
                continue;
            }

            $surface[$class->getName()] = $class;

            foreach (PublicFace::of($class)->coreTypes() as $reached) {
                $pending[] = new ReflectionClass($reached);
            }
        }

        return array_values($surface);
    }

    /**
     * The public methods a class declares itself. The methods PHP gives every
     * enum, `cases()`, `from()` and `tryFrom()`, are PHP's and not the class's.
     *
     * @param  ReflectionClass<object> $class
     * @return list<ReflectionMethod>
     */
    public static function publicMethodsOf(ReflectionClass $class): array
    {
        return array_values(array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName() && ! $method->isInternal(),
        ));
    }

    /**
     * Every method a class declares itself, whatever its visibility, less the
     * ones PHP gives every enum.
     *
     * @param  ReflectionClass<object> $class
     * @return list<ReflectionMethod>
     */
    public static function methodsOf(ReflectionClass $class): array
    {
        return array_values(array_filter(
            $class->getMethods(),
            static fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName() && ! $method->isInternal(),
        ));
    }

    /**
     * Every type name a property's type spells, `null` included where it
     * allows it.
     *
     * @return list<string>
     */
    public static function propertyNames(ReflectionProperty $property): array
    {
        $type = $property->getType();

        return $type instanceof ReflectionType ? self::namesIn($type) : [];
    }

    public static function describe(ReflectionMethod $method): string
    {
        return sprintf('%s::%s()', $method->getDeclaringClass()->getName(), $method->getName());
    }

    /**
     * Every type name a method's return type spells, `null` included where it
     * allows it.
     *
     * @return list<string>
     */
    public static function returnNames(ReflectionMethod $method): array
    {
        $type = $method->getReturnType();

        return $type instanceof ReflectionType ? self::namesIn($type) : [];
    }

    /**
     * Every type name a parameter's type spells, `null` included where it
     * allows it.
     *
     * @return list<string>
     */
    public static function parameterNames(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionType ? self::namesIn($type) : [];
    }

    /** @return list<string> */
    private static function namesIn(ReflectionType $type): array
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return array_merge([], ...array_map(self::namesIn(...), $type->getTypes()));
        }

        $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

        return $type->allowsNull() && ! in_array($name, ['null', 'mixed'], strict: true) ? [$name, 'null'] : [$name];
    }
}
