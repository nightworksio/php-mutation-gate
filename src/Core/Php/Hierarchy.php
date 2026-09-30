<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_any;
use function array_key_exists;

/**
 * The classes, interfaces, traits and enums the project declares, each with
 * what it extends and implements, so that a name can be followed to the class
 * whose constant, case or property it reaches.
 */
final readonly class Hierarchy
{
    /** @param array<string, ClassLike> $classes each named class-like, by its name in lower case */
    private function __construct(private array $classes)
    {
    }

    public static function of(Source ...$sources): self
    {
        $classes = [];

        foreach ($sources as $source) {
            foreach ($source->shape()->classes() as $class) {
                $classes = $class->key() === '' ? $classes : [...$classes, $class->key() => $class];
            }
        }

        return new self($classes);
    }

    /**
     * Whether a class is the owner, or reaches it through what it extends or
     * implements without passing a class that declares the constant anew. An
     * empty constant is shadowed by nothing.
     */
    public function reaches(string $class, string $owner, string $constant): bool
    {
        return $this->reachedFrom([$class], $owner, $constant, []);
    }

    /** Whether any of these names may be a class that reaches the owner. */
    public function anyReaches(Names $names, string $owner, string $constant): bool
    {
        return array_any($names->all(), fn(string $name): bool => $this->reaches($name, $owner, $constant));
    }

    /** Whether a class other than the owner that reaches it declares the constant anew. */
    public function overrides(string $owner, string $constant): bool
    {
        return array_any(
            $this->classes,
            fn(ClassLike $class, string $name): bool => $name !== $owner
                && $class->declares($constant)
                && $this->reaches($name, $owner, ''),
        );
    }

    /**
     * @param list<string>        $classes
     * @param array<string, true> $seen
     */
    private function reachedFrom(array $classes, string $owner, string $constant, array $seen): bool
    {
        $next = [];

        foreach ($classes as $class) {
            if ($class === $owner) {
                return true;
            }

            $next = [...$next, ...$this->parentsOf($class, $constant, $seen)];
            $seen[$class] = true;
        }

        return $next !== [] && $this->reachedFrom($next, $owner, $constant, $seen);
    }

    /**
     * What a class extends and implements, unless it declares the constant
     * anew or was followed before.
     *
     * @param  array<string, true> $seen
     * @return list<string>
     */
    private function parentsOf(string $class, string $constant, array $seen): array
    {
        $known = array_key_exists($class, $this->classes) && ! array_key_exists($class, $seen);

        return $known && ($constant === '' || ! $this->classes[$class]->declares($constant))
            ? $this->classes[$class]->parents()->all()
            : [];
    }
}
