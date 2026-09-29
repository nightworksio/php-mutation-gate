<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_float;
use function is_int;

use NightWorksIO\MutationGate\Core\Config\Absent;

use function sprintf;

/** A number, whole or not, in a range. A number written as a string is not one. */
final readonly class Number implements Node
{
    private function __construct(private int|float $least, private int|float|Absent $most)
    {
    }

    public static function between(int|float $least, int|float $most): self
    {
        return new self($least, $most);
    }

    public static function atLeast(int|float $least): self
    {
        return new self($least, Absent::setting());
    }

    public function read(mixed $value, string $at): Reading
    {
        return (is_int($value) || is_float($value)) && $value >= $this->least && $this->fits($value)
            ? Reading::of((float) $value, $value)
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return $this->most instanceof Absent
            ? sprintf('a number of at least %s', $this->least)
            : sprintf('a number from %s to %s', $this->least, $this->most);
    }

    public function schema(): array
    {
        return $this->most instanceof Absent
            ? ['type' => 'number', 'minimum' => $this->least]
            : ['type' => 'number', 'minimum' => $this->least, 'maximum' => $this->most];
    }

    public function effects(): array
    {
        return [];
    }

    private function fits(int|float $value): bool
    {
        return $this->most instanceof Absent || $value <= $this->most;
    }
}
