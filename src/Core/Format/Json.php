<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_is_list;
use function array_key_exists;
use function array_map;
use function get_object_vars;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;

use NightWorksIO\MutationGate\Core\Config\Absent;
use stdClass;

/**
 * A JSON value the gate writes, and how the gate writes JSON: slashes and
 * non-ASCII text as they are, whole-number floats with their fraction, and
 * bytes that are not UTF-8, such as a diff of a file in another encoding,
 * replaced rather than refused. A person reads it indented, one key per line.
 */
final readonly class Json
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct(private mixed $value)
    {
    }

    /**
     * A file the gate writes, indented.
     *
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode($data, self::FLAGS | JSON_PRETTY_PRINT);
    }

    /**
     * A file only the gate reads, which can be large, on one line.
     *
     * @param array<mixed> $data
     */
    public static function compact(array $data): string
    {
        return json_encode($data, self::FLAGS);
    }

    /** An object with no keys yet, written `{}`. */
    public static function object(): self
    {
        return new self(new stdClass());
    }

    /** @param list<self|string|int|float|bool> $items */
    public static function items(array $items): self
    {
        return new self(array_map(self::held(...), $items));
    }

    /** A value a decoder read, as it read it. */
    public static function decoded(mixed $value): self
    {
        return new self($value);
    }

    /** This object with a key set, replacing what it held there. */
    public function with(string $key, self|string|int|float|bool $value): self
    {
        $members = $this->members();
        $members[$key] = self::held($value);

        return new self($members);
    }

    /** What this object holds under a key, or nothing. */
    public function member(string $key): self|Absent
    {
        $members = $this->members();

        return array_key_exists($key, $members) ? new self($members[$key]) : Absent::setting();
    }

    /**
     * This object with another's keys laid over its own, as layers of config are: objects merge by key, lists
     * concatenate without repeating an entry, and any other value is replaced.
     */
    public function merged(self $later): self
    {
        $merged = self::laid($this->value, $later->value);

        return new self($merged === [] ? new stdClass() : $merged);
    }

    /** Whether this is an object or a list with nothing in it. */
    public function isEmpty(): bool
    {
        return $this->members() === [];
    }

    /** Indented by four spaces, as a person reads it. */
    public function pretty(): string
    {
        return json_encode($this->value, self::FLAGS | JSON_PRETTY_PRINT);
    }

    /** On one line. */
    public function line(): string
    {
        return json_encode($this->value, self::FLAGS);
    }

    /** The value as PHP holds decoded JSON, for a writer of another format: an empty object as an empty list. */
    public function plain(): mixed
    {
        return json_decode($this->line(), associative: true);
    }

    private static function held(self|string|int|float|bool $value): mixed
    {
        return $value instanceof self ? $value->value : $value;
    }

    /** @return array<mixed> the keys of this object, or the items of this list */
    private function members(): array
    {
        return match (true) {
            is_array($this->value) => $this->value,
            $this->value instanceof stdClass => get_object_vars($this->value),
            default => [],
        };
    }

    private static function laid(mixed $earlier, mixed $later): mixed
    {
        $earlierIsList = is_array($earlier) && array_is_list($earlier) && $earlier !== [];
        $laterIsList = is_array($later) && array_is_list($later) && $later !== [];

        return match (true) {
            $earlierIsList && $laterIsList => self::concatenated($earlier, $later),
            self::isObject($earlier) && self::isObject($later) => self::mergedMembers(
                new self($earlier)->members(),
                new self($later)->members(),
            ),
            default => $later,
        };
    }

    /**
     * @param  list<mixed> $earlier
     * @param  list<mixed> $later
     * @return list<mixed>
     */
    private static function concatenated(array $earlier, array $later): array
    {
        $merged = $earlier;

        foreach ($later as $entry) {
            if (! in_array($entry, $merged, strict: true)) {
                $merged[] = $entry;
            }
        }

        return $merged;
    }

    /**
     * @param  array<mixed> $earlier
     * @param  array<mixed> $later
     * @return array<mixed>
     */
    private static function mergedMembers(array $earlier, array $later): array
    {
        $merged = $earlier;

        foreach ($later as $key => $value) {
            $merged[$key] = array_key_exists($key, $earlier) ? self::laid($earlier[$key], $value) : $value;
        }

        return $merged;
    }

    /** Whether a held value is an object: a map, or `{}`. */
    private static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && ($value === [] || ! array_is_list($value)));
    }
}
