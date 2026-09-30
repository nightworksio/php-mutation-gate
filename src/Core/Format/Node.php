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

use NightWorksIO\MutationGate\Core\Config\Problem;

use function sprintf;

/**
 * One place in a decoded JSON text, read as the type it should hold. In a
 * file the gate wrote, a place that holds something else, or nothing, is
 * refused with where it is, so a reader drops the entry or refuses the file
 * rather than guessing. A config a person wrote is read by first asking what
 * a place holds, so that every mistake in it is reported at once.
 */
final readonly class Node
{
    private const string ROOT = 'the file';

    /** The path of a config's own keys, which have nothing before them. */
    private const string CONFIG = '';

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

    /** The top of a config's JSON text, whose keys are named with nothing before them: `trees[1].floor`. */
    public static function config(string $json): self
    {
        return new self(json_decode($json, associative: true), self::CONFIG, present: true);
    }

    /** The place under a key of this one, which is absent, and holds nothing readable, where this holds no such key. */
    public function field(string $key): self
    {
        $present = is_array($this->value) && array_key_exists($key, $this->value);
        $at = $this->at === self::CONFIG ? $key : sprintf('%s.%s', $this->at, $key);

        return new self($present ? $this->value[$key] : $this, $at, $present);
    }

    /** The entry under a key of a map whose keys are data rather than settings, named `at["key"]`. */
    public function entry(string $key): self
    {
        $present = is_array($this->value) && array_key_exists($key, $this->value);

        return new self($present ? $this->value[$key] : $this, sprintf('%s["%s"]', $this->at, $key), $present);
    }

    /** What this place holds, to be asked before it is read where it was written by a person. */
    public function kind(): Kind
    {
        return match (true) {
            ! $this->present => Kind::Nothing,
            $this->value === [] => Kind::Empty,
            is_array($this->value) => array_is_list($this->value) ? Kind::List : Kind::Map,
            is_string($this->value) => Kind::Text,
            is_int($this->value) => Kind::Integer,
            is_float($this->value) => Kind::Number,
            is_bool($this->value) => Kind::Boolean,
            default => Kind::Null,
        };
    }

    /** That this place holds something other than what it should, said as a config's problem is: at its path. */
    public function mismatch(string $expected): Problem
    {
        return Problem::at($this->at, sprintf('expected %s, got %s', $expected, $this->got()));
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
     * A list of whole numbers, read at once, for a list too long to read one
     * place at a time.
     *
     * @return list<int>
     *
     * @throws NotInShape
     */
    public function integers(): array
    {
        if (! is_array($this->value) || ! array_is_list($this->value)) {
            throw $this->refused('a list of whole numbers');
        }

        $integers = [];

        foreach ($this->value as $item) {
            $integers[] = is_int($item) ? $item : throw $this->refused('a list of whole numbers');
        }

        return $integers;
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

    /**
     * What this place holds, as the JSON value it is: an empty `{}` or `[]` is empty either way.
     *
     * @throws NotInShape
     */
    public function value(): Json
    {
        return $this->present ? Json::decoded($this->value) : throw NotInShape::missing($this->at);
    }

    /** What this place holds, as a problem says it: a value as JSON writes it, a list or an object, or nothing. */
    private function got(): string
    {
        return match ($this->kind()) {
            Kind::Nothing => 'nothing',
            Kind::List, Kind::Empty => 'a list',
            Kind::Map => 'an object',
            Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => json_encode($this->value, self::FLAGS),
        };
    }

    private function refused(string $expected): NotInShape
    {
        return $this->present ? NotInShape::at($this->at, $expected) : NotInShape::missing($this->at);
    }
}
