<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_is_list;
use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

/**
 * One place in a decoded JSON file the gate wrote, read as the type it should
 * hold. A place that holds something else, or nothing, is refused with where
 * it is, so a reader drops the entry or refuses the file rather than guessing.
 */
final readonly class Node
{
    private const string ROOT = 'the file';

    /** How a place is written back out: as it was read, slashes and non-ASCII text as they are. */
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    private function __construct(private mixed $value, private string $at, private bool $present)
    {
    }

    /** The top of a JSON text; text that is not JSON reads as a place holding nothing that can be read. */
    public static function decode(string $json): self
    {
        return new self(json_decode($json, associative: true), self::ROOT, present: true);
    }

    /** The place under a key of this one, which is absent, and holds nothing readable, where this holds no such key. */
    public function field(string $key): self
    {
        $present = is_array($this->value) && array_key_exists($key, $this->value);

        return new self($present ? $this->value[$key] : $this, sprintf('%s.%s', $this->at, $key), $present);
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    /** Where this place is, as a message names it. */
    public function at(): string
    {
        return $this->at;
    }

    /** @throws NotInShape */
    public function text(): string
    {
        return is_string($this->value) ? $this->value : throw $this->refused('text');
    }

    /** @throws NotInShape */
    public function boolean(): bool
    {
        return is_bool($this->value) ? $this->value : throw $this->refused('true or false');
    }

    /** @throws NotInShape */
    public function integer(): int
    {
        return is_int($this->value) ? $this->value : throw $this->refused('a whole number');
    }

    /** @throws NotInShape */
    public function number(): float
    {
        return is_int($this->value) || is_float($this->value) ? $this->value : throw $this->refused('a number');
    }

    /**
     * @return list<self>
     *
     * @throws NotInShape
     */
    public function items(): array
    {
        if (! is_array($this->value) || ! array_is_list($this->value)) {
            throw $this->refused('a list');
        }

        $items = [];

        foreach ($this->value as $index => $item) {
            $items[] = new self($item, sprintf('%s[%d]', $this->at, $index), present: true);
        }

        return $items;
    }

    /**
     * @return array<string, self>
     *
     * @throws NotInShape
     */
    public function entries(): array
    {
        if (! is_array($this->value)) {
            throw $this->refused('a map');
        }

        $entries = [];

        foreach ($this->value as $key => $entry) {
            $entries[sprintf('%s', $key)] = new self($entry, sprintf('%s.%s', $this->at, $key), present: true);
        }

        return $entries;
    }

    /**
     * What this place holds, written back out as JSON. An empty object reads
     * as a map with no keys, and is written as an empty list.
     *
     * @throws NotInShape
     */
    public function json(): string
    {
        return $this->present ? json_encode($this->value, self::FLAGS) : throw NotInShape::missing($this->at);
    }

    private function refused(string $expected): NotInShape
    {
        return $this->present ? NotInShape::at($this->at, $expected) : NotInShape::missing($this->at);
    }
}
