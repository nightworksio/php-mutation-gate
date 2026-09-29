<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Absent;

/**
 * The typed values of an object's settings, by key, read cleanly. Each
 * accessor names the type the setting's definition produces.
 */
final readonly class Fields
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    /** Whether the setting was written or has a default. */
    public function has(string $key): bool
    {
        return ! $this->values[$key] instanceof Absent;
    }

    public function int(string $key): int
    {
        return is_int($this->values[$key]) ? $this->values[$key] : throw MisreadSetting::as($key, 'an integer');
    }

    public function float(string $key): float
    {
        return is_float($this->values[$key]) ? $this->values[$key] : throw MisreadSetting::as($key, 'a number');
    }

    public function bool(string $key): bool
    {
        return is_bool($this->values[$key]) ? $this->values[$key] : throw MisreadSetting::as($key, 'a boolean');
    }

    public function string(string $key): string
    {
        return is_string($this->values[$key]) ? $this->values[$key] : throw MisreadSetting::as($key, 'a string');
    }

    /**
     * @template T of object
     *
     * @param  class-string<T> $class
     * @return T
     */
    public function object(string $key, string $class): object
    {
        return $this->values[$key] instanceof $class ? $this->values[$key] : throw MisreadSetting::as($key, $class);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T> $class
     * @return T|Absent
     */
    public function optional(string $key, string $class): object
    {
        $value = $this->values[$key];

        return $value instanceof $class || $value instanceof Absent ? $value : throw MisreadSetting::as($key, $class);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T> $class
     * @return list<T>
     */
    public function objects(string $key, string $class): array
    {
        $objects = [];

        foreach ($this->list($key) as $item) {
            $objects[] = $item instanceof $class ? $item : throw MisreadSetting::as($key, $class);
        }

        return $objects;
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $strings = [];

        foreach ($this->list($key) as $item) {
            $strings[] = is_string($item) ? $item : throw MisreadSetting::as($key, 'a string');
        }

        return $strings;
    }

    /** The settings of the object under a key. */
    public function fields(string $key): self
    {
        return $this->object($key, self::class);
    }

    /** @return array<mixed> */
    private function list(string $key): array
    {
        return is_array($this->values[$key]) ? $this->values[$key] : throw MisreadSetting::as($key, 'a list');
    }
}
