<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_values;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A JSON value as it stands in its text: where it starts, where it ends, past
 * its last byte, and, for an object, its members in the order written.
 */
final readonly class JsonSpan
{
    /** @param list<JsonMember> $members */
    private function __construct(public int $start, public int $end, public array $members, public bool $isObject)
    {
    }

    public static function object(int $start, int $end, JsonMember ...$members): self
    {
        return new self($start, $end, array_values($members), isObject: true);
    }

    /** A value that is no object: an array, a string, a number, `true`, `false` or `null`. */
    public static function other(int $start, int $end): self
    {
        return new self($start, $end, [], isObject: false);
    }

    /** The member under this key, where it is an object that holds one. */
    public function member(string $key): JsonMember|NotGiven
    {
        foreach ($this->members as $member) {
            if ($member->key === $key) {
                return $member;
            }
        }

        return NotGiven::value();
    }
}
