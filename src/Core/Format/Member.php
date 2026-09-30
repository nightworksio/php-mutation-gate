<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use NightWorksIO\MutationGate\Core\Config\Absent;

/**
 * A key of a JSON object and what it holds. A key that holds nothing is
 * left out of the object, so a setting a layer leaves out is not written.
 */
final readonly class Member
{
    private function __construct(private string $key, private Json|string|int|float|bool|Absent $value)
    {
    }

    public static function of(string $key, Json|string|int|float|bool|Absent $value): self
    {
        return new self($key, $value);
    }

    /** A key that holds this object or list, left out where it holds nothing. */
    public static function unlessEmpty(string $key, Json $value): self
    {
        return new self($key, $value->isEmpty() ? Absent::setting() : $value);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): Json|string|int|float|bool|Absent
    {
        return $this->value;
    }
}
