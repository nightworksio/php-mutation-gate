<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_string;

use NightWorksIO\MutationGate\Core\Time\Day;

/** A calendar day, written `YYYY-MM-DD`. */
final readonly class Date implements Node
{
    public static function written(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        $day = is_string($value) ? Day::of($value) : $value;

        return $day instanceof Day ? Reading::of($day, $value) : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'a date written YYYY-MM-DD';
    }

    public function schema(): array
    {
        return ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
    }

    public function effects(): array
    {
        return [];
    }
}
