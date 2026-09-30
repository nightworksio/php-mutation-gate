<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function sprintf;

/**
 * One `#[Holds]` a test file writes: where it stands, the path it names and
 * the line it is written on, with the class, method or function it stands on
 * and, for a class or method, whether PHPUnit's `#[Group('holds:<path>')]`
 * stands beside it.
 */
final readonly class HoldsAttribute
{
    private function __construct(
        private Standing $standing,
        private HeldPath $path,
        private int $line,
        private string $holder,
        private bool $grouped,
    ) {
    }

    /** On a closure, or anywhere else that has no name. */
    public static function at(Standing $standing, HeldPath $path, int $line): self
    {
        return new self($standing, $path, $line, '', grouped: false);
    }

    /** On a function declared with a name, fully qualified. */
    public static function onFunction(HeldPath $path, int $line, string $function): self
    {
        return new self(Standing::NamedFunction, $path, $line, $function, grouped: false);
    }

    /** On a class, fully qualified. */
    public static function onClass(HeldPath $path, int $line, string $class, bool $grouped): self
    {
        return new self(Standing::TestClass, $path, $line, $class, $grouped);
    }

    /** On a method, written as `Class::method`. */
    public static function onMethod(HeldPath $path, int $line, string $method, bool $grouped): self
    {
        return new self(Standing::TestMethod, $path, $line, $method, $grouped);
    }

    public function standing(): Standing
    {
        return $this->standing;
    }

    public function path(): HeldPath
    {
        return $this->path;
    }

    /** The line the attribute is written on, from 1. */
    public function line(): int
    {
        return $this->line;
    }

    /** The class, method or function it stands on; empty on a closure. */
    public function holder(): string
    {
        return $this->holder;
    }

    /** Whether `#[Group('holds:<path>')]` stands beside it, with the path as one string literal. */
    public function isGrouped(): bool
    {
        return $this->grouped;
    }

    /** The attribute as a test writes it, to name it in a message. */
    public function written(): string
    {
        return sprintf('#[Holds(%s)]', $this->path->written());
    }
}
