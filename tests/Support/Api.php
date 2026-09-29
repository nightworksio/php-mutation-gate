<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_pop;
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
 * in Port, Config and Extension, and every core type their public signatures
 * reach, followed as far as it goes (ADR-0001).
 */
final readonly class Api
{
    /**
     * Every class-like PSR-4 places under a directory of `src`, loaded. A file
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
        $pending = [
            ...self::classesUnder(Layer::Port->directory()),
            ...self::classesUnder(Layer::Config->directory()),
            ...self::classesUnder(Layer::Extension->directory()),
        ];

        while ($pending !== []) {
            $class = array_pop($pending);

            if (array_key_exists($class->getName(), $surface)) {
                continue;
            }

            $surface[$class->getName()] = $class;

            foreach (self::publicMethodsOf($class) as $method) {
                foreach (self::coreTypesIn($method) as $reached) {
                    $pending[] = new ReflectionClass($reached);
                }
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

    /**
     * The core types a method's signature names.
     *
     * @return list<class-string>
     */
    private static function coreTypesIn(ReflectionMethod $method): array
    {
        $names = self::returnNames($method);

        foreach ($method->getParameters() as $parameter) {
            $names = [...$names, ...self::parameterNames($parameter)];
        }

        $found = [];

        foreach ($names as $name) {
            if (Layer::Core->holds($name) && (class_exists($name) || interface_exists($name) || enum_exists($name))) {
                $found[] = $name;
            }
        }

        return $found;
    }
}
