<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_float;
use function is_int;

use NightWorksIO\MutationGate\Core\Score\Floor;

/** A floor: a number from 0 to 100, kept to the hundredth it was written to (ADR-0003). */
final readonly class Percent implements Node
{
    private const int NONE = 0;

    private const int WHOLE = 100;

    public static function floor(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        return (is_int($value) || is_float($value)) && $value >= self::NONE && $value <= self::WHOLE
            ? Reading::of(Floor::of($value), $value)
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'a number from 0 to 100';
    }

    public function schema(): array
    {
        return ['type' => 'number', 'minimum' => self::NONE, 'maximum' => self::WHOLE];
    }

    public function effects(): array
    {
        return [];
    }
}
