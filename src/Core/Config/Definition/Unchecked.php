<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

/**
 * A value the gate keeps but never reads, such as the `$schema` an editor
 * checks a JSON config against: any value is taken as written.
 */
final readonly class Unchecked implements Node
{
    private function __construct(private string $description)
    {
    }

    public static function describedAs(string $description): self
    {
        return new self($description);
    }

    public function read(mixed $value, string $at): Reading
    {
        return Reading::of($value, $value);
    }

    public function expected(): string
    {
        return 'anything';
    }

    public function schema(): array
    {
        return ['description' => $this->description];
    }

    public function effects(): array
    {
        return [];
    }
}
