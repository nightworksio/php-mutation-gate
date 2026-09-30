<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_pop;
use function array_reverse;
use function array_values;
use function explode;
use function get_object_vars;
use function in_array;
use function is_array;

use Iterator;
use IteratorAggregate;

use function json_decode;
use function json_encode;
use function json_last_error_msg;
use function json_validate;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;

use function sprintf;

use stdClass;

/**
 * A JSON value, such as a layer of config as a file writes it, built only
 * from its parts: an object from its members, a list from its items. An
 * object stays an object when it holds nothing, written `{}`.
 *
 * @implements IteratorAggregate<int|string, Json>
 */
final readonly class Json implements IteratorAggregate
{
    /** The value as `json_decode` reads its JSON: an object as a `stdClass`, a list as a list. */
    private mixed $value;

    private function __construct(mixed $value)
    {
        $this->value = json_decode(json_encode($value, JsonText::FLAGS));
    }

    /** An object of these members, a later one replacing an earlier one of the same key. */
    public static function object(Member ...$members): self
    {
        return new self(self::set([], array_values($members)));
    }

    public static function items(self|string|int|float|bool ...$items): self
    {
        return new self(array_values(array_map(self::held(...), $items)));
    }

    /** A value under a path of keys, each an object of the next: `ci.gitlab.template`. */
    public static function at(string $path, self|string|int|float|bool $value): self
    {
        $keys = explode('.', $path);
        $at = self::object(Member::of(array_pop($keys), $value));

        foreach (array_reverse($keys) as $key) {
            $at = self::object(Member::of($key, $at));
        }

        return $at;
    }

    /** JSON text, or why it is not JSON, as the decoder says it: `Syntax error.` */
    public static function parse(string $json): self|CannotJudge
    {
        return json_validate($json)
            ? new self(json_decode($json))
            : CannotJudge::because(sprintf('%s.', json_last_error_msg()));
    }

    /** This object with these members set, each replacing what it held under its key. */
    public function with(Member ...$members): self
    {
        return new self(self::set(self::members($this->value), array_values($members)));
    }

    /**
     * This value with a later one laid over it, as layers of config are: objects merge by key, lists concatenate
     * without repeating an entry, and any other value is replaced.
     */
    public function merged(self $later): self
    {
        return new self(self::laid($this->value, $later->value));
    }

    /** Whether this is an object or a list with nothing in it. */
    public function isEmpty(): bool
    {
        return self::members($this->value) === [];
    }

    /** @return Iterator<int|string, Json> each member of an object by its key, or each item of a list */
    public function getIterator(): Iterator
    {
        foreach (self::members($this->value) as $key => $value) {
            yield $key => new self($value);
        }
    }

    /** Indented by four spaces, as a person reads it. */
    public function pretty(): string
    {
        return json_encode($this->value, JsonText::FLAGS | JSON_PRETTY_PRINT);
    }

    /** On one line. */
    public function line(): string
    {
        return json_encode($this->value, JsonText::FLAGS);
    }

    /**
     * An object's members with these set, `{}` where it has none.
     *
     * @param array<mixed> $object
     * @param list<Member> $members
     */
    private static function set(array $object, array $members): mixed
    {
        foreach ($members as $member) {
            $value = $member->value();

            if (! $value instanceof Absent) {
                $object[$member->key()] = self::held($value);
            }
        }

        return $object === [] ? new stdClass() : $object;
    }

    /** @return array<mixed> the members of an object, or the items of a list */
    private static function members(mixed $value): array
    {
        return match (true) {
            is_array($value) => $value,
            $value instanceof stdClass => get_object_vars($value),
            default => [],
        };
    }

    /** Whether a held value is an object: `{}`, or keys that are not a list's. */
    private static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && $value !== [] && ! array_is_list($value));
    }

    private static function held(self|string|int|float|bool $value): mixed
    {
        return $value instanceof self ? $value->value : $value;
    }


    private static function laid(
        mixed $earlier,
        mixed $later,
    ): mixed {
        return match (true) {
            self::isObject($earlier) && self::isObject($later) => self::set(
                self::mergedMembers(self::members($earlier), self::members($later)),
                [],
            ),
            is_array($earlier) && is_array($later) => self::concatenated($earlier, $later),
            default => $later,
        };
    }

    /**
     * @param  array<mixed> $earlier
     * @param  array<mixed> $later
     * @return list<mixed>
     */
    private static function concatenated(array $earlier, array $later): array
    {
        $merged = array_values($earlier);
        $written = array_map(self::written(...), $merged);

        foreach ($later as $entry) {
            if (! in_array(self::written($entry), $written, strict: true)) {
                $merged[] = $entry;
                $written[] = self::written($entry);
            }
        }

        return $merged;
    }

    /** A value as its JSON, so that two values are the same entry exactly when they are written alike. */
    private static function written(mixed $value): string
    {
        return json_encode($value, JsonText::FLAGS);
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
}
