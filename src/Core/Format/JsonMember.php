<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

/**
 * A member of a JSON object as it stands in its text: its key, decoded,
 * where the key's string starts and ends, and its value.
 */
final readonly class JsonMember
{
    private function __construct(public string $key, public int $keyStart, public int $keyEnd, public JsonSpan $value)
    {
    }

    public static function of(string $key, int $keyStart, int $keyEnd, JsonSpan $value): self
    {
        return new self($key, $keyStart, $keyEnd, $value);
    }
}
