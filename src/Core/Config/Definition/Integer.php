<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_int;
use function sprintf;

/** A whole number, of at least some least value. A number written as a string, or with a fraction, is not one. */
final readonly class Integer implements Node
{
    private function __construct(private int $least)
    {
    }

    public static function atLeast(int $least): self
    {
        return new self($least);
    }

    public function read(mixed $value, string $at): Reading
    {
        return is_int($value) && $value >= $this->least
            ? Reading::of($value, $value)
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return sprintf('an integer of at least %d', $this->least);
    }

    public function schema(): array
    {
        return ['type' => 'integer', 'minimum' => $this->least];
    }

    public function effects(): array
    {
        return [];
    }
}
