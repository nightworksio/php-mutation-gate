<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_any;

/**
 * What a class declares anew that stops a read through it reaching the class
 * above: a constant of the same name, or nothing where no declaration can.
 */
final readonly class Shadowing
{
    /** @param list<string> $constants */
    private function __construct(private array $constants)
    {
    }

    /** Nothing a class declares hides the class above it. */
    public static function none(): self
    {
        return new self([]);
    }

    /** A class that declares this constant anew hides it in the class above. */
    public static function byConstant(string $name): self
    {
        return new self([$name]);
    }

    public function hides(ClassLike $class): bool
    {
        return array_any($this->constants, $class->declares(...));
    }
}
